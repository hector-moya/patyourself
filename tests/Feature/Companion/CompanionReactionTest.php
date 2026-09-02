<?php

namespace Tests\Feature\Companion;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\ActionLogWebTest;
use Tests\Feature\NotebookDashboardTest;
use Tests\TestCase;

/**
 * Blob moves at the moment an outcome is recorded, not the next time the
 * dashboard happens to be opened.
 *
 * Carried as a one-request session flash rather than as anything stored: the
 * reward belongs to the act, and a stored flag would replay it on every visit
 * until something cleared it — which is exactly the bug this shape avoids.
 */
class CompanionReactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-09-02 09:00:00');
    }

    /**
     * Same shape as {@see ActionLogWebTest::action()} and
     * {@see NotebookDashboardTest::loopWithAction()}, extended
     * with the occasion those two didn't need together in one place.
     */
    private function occasion(User $user): Occurrence
    {
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['status' => Action::STATUS_ACTIVE]);

        return Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subHour(),
        ]);
    }

    public function test_recording_an_outcome_hands_the_dashboard_the_log_it_wrote(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occasion($user);

        $this->actingAs($user)
            ->post("/occurrences/{$occurrence->id}/logs", ['outcome' => 'completed'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logged_outcome_id', ActionLog::firstOrFail()->id),
            );
    }

    /**
     * The one that matters. Coming back to the dashboard later must not replay
     * a reward that was already given.
     */
    public function test_the_reaction_does_not_replay_on_the_next_visit(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = $this->occasion($user);

        $this->actingAs($user)->post("/occurrences/{$occurrence->id}/logs", ['outcome' => 'completed']);
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('logged_outcome_id', null));
    }

    public function test_a_plain_visit_carries_no_reaction(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logged_outcome_id')
                ->where('logged_outcome_id', null),
            );
    }

    public function test_the_action_keyed_endpoint_reacts_too(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->occasion($user)->action;

        $this->actingAs($user)
            ->post("/actions/{$action->id}/logs", ['outcome' => 'skipped'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logged_outcome_id', ActionLog::firstOrFail()->id),
            );
    }
}
