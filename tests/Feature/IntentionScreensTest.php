<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The loops-list and loop-detail screen routing — each an Inertia page
 * rendered in the shared CoachLayout shell. Verifies the controllers hand
 * each screen the right component + props and gate detail on ownership.
 */
class IntentionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Screen content ships in Tasks 18–20; the page render only needs
        // Inertia's component + props, not a built Vite manifest.
        $this->withoutVite();
    }

    public function test_guests_are_redirected_from_the_loops_list(): void
    {
        $this->get('/loops')->assertRedirect('/login');
    }

    public function test_loops_list_renders_only_the_users_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create();
        Intention::factory()->create();

        $this->actingAs($user)
            ->get('/loops')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/index')
                ->has('intentions', 2)
            );
    }

    public function test_loops_list_surfaces_active_loops_first(): void
    {
        $user = User::factory()->create();
        $archived = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ARCHIVED]);
        $active = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get('/loops')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/index')
                ->where('intentions.0.id', $active->id)
                ->where('intentions.1.id', $archived->id)
            );
    }

    public function test_loop_detail_renders_anatomy_and_the_strategy_timeline(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $v1 = $intention->strategies()->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay the book on the pillow',
            'rationale' => 'Make the cue impossible to miss',
            'change_reason' => Strategy::REASON_INITIAL,
            'superseded_reason' => 'Kept forgetting once in bed',
        ]);
        $intention->strategies()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Read a single page, no more',
            'rationale' => 'Shrink the response',
            'change_reason' => Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            'parent_strategy_id' => $v1->id,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/show')
                ->where('intention.id', $intention->id)
                // The active strategy drives which anatomy stage is highlighted.
                ->where('intention.strategy.intervention_point', Strategy::POINT_RESPONSE)
                // Timeline reads oldest version first.
                ->has('strategies', 2)
                ->where('strategies.0.version', 1)
                ->where('strategies.1.version', 2)
            );
    }

    /**
     * Zero is meaningful: it is the difference between a version that failed
     * and one that was never tested. Without the count the timeline cannot
     * tell them apart.
     */
    public function test_loop_detail_counts_the_outcomes_recorded_under_each_version(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $tested = Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
        ]);
        Strategy::factory()->for($intention)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        ActionLog::factory()->count(3)
            ->for(Action::factory()->for($intention)->for($tested))
            ->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_FAILED, 'reason' => 'Kept going past full']);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('strategies.0.outcomes_recorded', 3)
                ->where('strategies.1.outcomes_recorded', 0)
                ->etc()
            );
    }

    /**
     * The occurrence entity's whole point, rendered: an entry sits in the
     * history where the occasion happened, not where it was typed.
     */
    public function test_loop_detail_orders_the_outcome_history_by_the_occasion(): void
    {
        $this->travelTo('2026-08-26 21:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($intention)->create(['title' => 'Dinner']);

        // Logged in the same check-in, minutes apart, but describing occasions
        // five days apart.
        foreach (['2026-08-20 19:00:00', '2026-08-25 19:00:00'] as $occasion) {
            $occurrence = Occurrence::factory()->create([
                'action_id' => $action->id,
                'scheduled_for' => $occasion,
            ]);
            ActionLog::factory()->create([
                'action_id' => $action->id,
                'occurrence_id' => $occurrence->id,
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('outcomes', 2)
                ->where('outcomes.0.occurred_at', '2026-08-25T19:00:00+00:00')
                ->where('outcomes.1.occurred_at', '2026-08-20T19:00:00+00:00')
                ->where('outcomes_total', 2)
                ->where('showing_all_history', false)
                ->etc()
            );
    }

    public function test_loop_detail_returns_the_reason_verbatim(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($intention)->create();
        $reason = "  didn't Think about it AT ALL.  ";

        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('outcomes.0.reason', $reason)
                ->etc()
            );
    }

    public function test_loop_detail_shows_recent_history_by_default_and_all_on_request(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($intention)->create();

        ActionLog::factory()->count(35)->for($action)->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('outcomes', 30)
                ->where('outcomes_total', 35)
                ->etc()
            );

        $this->actingAs($user)
            ->get("/loops/{$intention->id}?history=all")
            ->assertInertia(fn (Assert $page) => $page
                ->has('outcomes', 35)
                ->where('showing_all_history', true)
                ->etc()
            );
    }

    public function test_loop_detail_carries_its_notes_newest_first(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Note::factory()->for($intention)->create([
            'body' => 'Older observation',
            'noted_at' => '2026-08-20 10:00:00',
        ]);
        Note::factory()->for($intention)->create([
            'body' => 'Newer observation',
            'noted_at' => '2026-08-25 10:00:00',
        ]);

        $this->actingAs($user)
            ->get("/loops/{$intention->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('notes', 2)
                ->where('notes.0.body', 'Newer observation')
                ->where('notes.1.body', 'Older observation')
                ->etc()
            );
    }

    public function test_loop_detail_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/loops/{$intention->id}")
            ->assertForbidden();
    }
}
