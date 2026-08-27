<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The daily-driver screen. Fortify sends every login to `/dashboard`, and it
 * used to render the loops index — so the first screen answered "what loops do
 * I have" rather than "what am I doing today".
 *
 * Today means the user's local day and only that. An occasion missed on an
 * earlier day never appears here; it stays loggable forever on /catch-up.
 */
class NotebookDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-08-27 12:00:00');
    }

    private function loopWithAction(User $user): Action
    {
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
        ]);
        $strategy = Strategy::factory()->for($loop)->create([
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        return Action::factory()->for($loop)->for($strategy)->create([
            'series_started_at' => CarbonImmutable::parse('2026-08-01 08:00:00'),
            'recurrence' => null,
        ]);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_it_renders_the_notebook_rather_than_the_loops_index(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
    }

    public function test_it_separates_what_is_due_now_from_what_is_later_today(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->loopWithAction($user);

        Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::parse('2026-08-27 09:00:00'),
        ]);
        Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::parse('2026-08-27 21:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('occasions', 2)
                ->where('occasions.0.due', 'due_now')
                ->where('occasions.1.due', 'upcoming')
            );
    }

    /**
     * The discriminating case for the whole screen. Yesterday's unlogged
     * occasion is still loggable forever — on /catch-up. If it leaked onto the
     * dashboard the screen would become a backlog, which is the one thing this
     * app will not do.
     */
    public function test_an_occasion_from_an_earlier_day_never_appears(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->loopWithAction($user);

        Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::parse('2026-08-26 09:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('occasions', 0));
    }

    public function test_each_occasion_carries_what_the_row_needs_to_log_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->loopWithAction($user);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::parse('2026-08-27 09:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // occurrence_id decides which endpoint the row posts to, so it
                // has to reach the client.
                ->where('occasions.0.occurrence_id', $occurrence->id)
                ->where('occasions.0.action_id', $action->id)
                ->where('occasions.0.loop_id', $action->intention_id)
                ->has('occasions.0.title')
                ->has('occasions.0.loop_title')
            );
    }

    public function test_another_users_occasions_never_appear(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);

        Occurrence::factory()->for($this->loopWithAction($stranger))->create([
            'scheduled_for' => CarbonImmutable::parse('2026-08-27 09:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('occasions', 0));
    }

    public function test_a_version_past_its_review_date_is_ready_for_a_verdict(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
            'title' => 'Morning walk',
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
            'review_at' => CarbonImmutable::parse('2026-08-20 09:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ready_for_verdict', 1)
                ->where('ready_for_verdict.0.loop_title', 'Morning walk')
                ->where('ready_for_verdict.0.version', 1)
            );
    }

    public function test_a_version_still_inside_its_window_is_not_ready(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
        ]);
        Strategy::factory()->for($loop)->create([
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-08-25 09:00:00'),
            'review_at' => CarbonImmutable::parse('2026-09-15 09:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('ready_for_verdict', 0));
    }

    /**
     * A superseded version never asks for a verdict, however long its review
     * date has been past.
     *
     * Note what this does and does not prove: the `activeStrategy` relation
     * already excludes a superseded row, so this guards the relation rather
     * than `isUnderReview()`. Swapping `isUnderReview()` for a bare `review_at`
     * comparison leaves it passing — verified by mutation — because for a row
     * the relation has already loaded the two are equivalent. The case that
     * separates them is an open-ended version, covered below.
     */
    public function test_a_superseded_version_past_its_review_date_is_not_ready(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
        ]);
        Strategy::factory()->for($loop)->create([
            'status' => Strategy::STATUS_SUPERSEDED,
            'created_at' => CarbonImmutable::parse('2026-08-01 09:00:00'),
            'review_at' => CarbonImmutable::parse('2026-08-20 09:00:00'),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('ready_for_verdict', 0));
    }

    /**
     * An open-ended experiment has no review date and can never be "ready for a
     * verdict" — there is no date for it to have passed. Running one
     * indefinitely is a legitimate way to work, and the dashboard must never
     * imply a decision is owed on it.
     */
    public function test_an_open_ended_version_is_never_ready_for_a_verdict(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_ACTIVE,
        ]);
        Strategy::factory()->for($loop)->create([
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-06-01 09:00:00'),
            'review_at' => null,
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('ready_for_verdict', 0));
    }

    public function test_it_carries_the_users_local_day(): void
    {
        $user = User::factory()->create(['timezone' => 'Pacific/Auckland']);

        // 2026-08-27 12:00 UTC is already the 28th in Auckland.
        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('today', '2026-08-28'));
    }
}
