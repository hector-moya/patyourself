<?php

namespace Tests\Feature\Progress;

use App\Actions\WriteReflection;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProgressShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_owner_sees_metrics_journey_and_narrative(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE, 'title' => 'Morning walk']);
        $v1 = Strategy::factory()->for($loop)->superseded('kept missing it')->create(['version' => 1]);
        $v2 = Strategy::factory()->for($loop)->restrategized()->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'parent_strategy_id' => $v1->id,
        ]);
        $action = Action::factory()->for($loop)->for($v2)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        Summary::factory()->for($loop)->create([
            'scope' => Summary::SCOPE_INTENTION,
            'content' => 'You complete most mornings.',
        ]);

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('progress/show')
                ->where('intention.title', 'Morning walk')
                ->where('intention.completion_rate', 100)
                ->where('intention.streak.length', 2)
                ->has('strategies', 2)
                ->where('strategies.0.version', 1) // ordered oldest-first
                ->where('strategies.1.version', 2)
                ->where('summary', 'You complete most mornings.')
            );
    }

    /**
     * The writer and the reader have to agree. `latestSummary()` filters to
     * intention scope, so a WriteReflection that wrote the wrong scope would be
     * silently ignored here and the screen would keep showing its empty state
     * while the record filled up. Every other test on this page seeds the row by
     * factory, which cannot catch that.
     */
    public function test_a_reflection_written_by_the_app_is_what_the_screen_renders(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        app(WriteReflection::class)->handle($loop, 'Dinner holds. Lunch is where it goes.');

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary', 'Dinner holds. Lunch is where it goes.')
            );
    }

    public function test_summary_is_null_when_absent(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Strategy::factory()->initial()->for($loop)->create();

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertInertia(fn (Assert $page) => $page->where('summary', null));
    }

    public function test_serves_a_non_active_owned_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->completed()->create();

        $this->actingAs($user)->get("/progress/{$loop->id}")->assertOk();
    }

    public function test_forbids_viewing_another_users_loop(): void
    {
        $owner = User::factory()->create();
        $loop = Intention::factory()->for($owner)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs(User::factory()->create())
            ->get("/progress/{$loop->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);

        $this->get("/progress/{$loop->id}")->assertRedirect('/login');
    }

    public function test_the_strategy_resource_carries_the_experiment_fields(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
            'review_at' => CarbonImmutable::parse('2026-09-22 12:00:00'),
            'verdict_note' => 'the cue moved but craving still spikes around 3pm',
        ]);

        $this->actingAs($user)
            ->get(route('progress.show', $intention))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('strategies.0.planned_days', 21)
                ->where('strategies.0.day_of_experiment', 12)
                ->where('strategies.0.is_under_review', false)
                ->where('strategies.0.verdict', null)
                ->where('strategies.0.review_at', '2026-09-22T12:00:00.000000Z')
                ->where('strategies.0.verdict_note', 'the cue moved but craving still spikes around 3pm'));
    }

    public function test_the_strategy_resource_serializes_an_open_ended_experiment(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        Strategy::factory()->for($intention)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
            'review_at' => null,
        ]);

        $this->actingAs($user)
            ->get(route('progress.show', $intention))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('strategies.0.review_at', null)
                ->where('strategies.0.planned_days', null)
                ->where('strategies.0.is_under_review', false));
    }
}
