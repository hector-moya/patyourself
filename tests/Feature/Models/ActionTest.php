<?php

namespace Tests\Feature\Models;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(Intention $intention): Action
    {
        return Action::factory()
            ->for($intention)
            ->for(Strategy::factory()->for($intention)->create(['version' => 1]), 'strategy')
            ->create();
    }

    public function test_an_action_is_bound_to_its_intention_strategy_and_logs()
    {
        $intention = Intention::factory()->create();
        $action = $this->action($intention);
        ActionLog::factory()->for($action, 'action')->for(User::factory())->count(2)->create();

        $this->assertTrue($action->intention->is($intention));
        $this->assertSame($intention->id, $action->strategy->intention_id);
        $this->assertCount(2, $action->logs);
    }

    public function test_action_log_relationships_and_outcome_predicates()
    {
        $user = User::factory()->create();
        $action = $this->action(Intention::factory()->create());

        $win = ActionLog::factory()->for($action, 'action')->for($user)->completed()->create();
        $miss = ActionLog::factory()->for($action, 'action')->for($user)->failed('too tired')->create();
        $skip = ActionLog::factory()->for($action, 'action')->for($user)->skipped()->create();

        $this->assertTrue($win->action->is($action));
        $this->assertTrue($win->user->is($user));

        $this->assertTrue($win->isWin());
        $this->assertTrue($miss->isFailure());
        $this->assertSame('too tired', $miss->reason);
        $this->assertTrue($skip->isSkip());
    }

    public function test_the_failures_scope_returns_only_misses()
    {
        $user = User::factory()->create();
        $action = $this->action(Intention::factory()->create());
        ActionLog::factory()->for($action, 'action')->for($user)->completed()->count(2)->create();
        ActionLog::factory()->for($action, 'action')->for($user)->failed()->create();

        $this->assertSame(1, ActionLog::failures()->count());
    }

    public function test_an_action_holds_only_a_lifecycle_status(): void
    {
        $this->assertSame(
            [Action::STATUS_ACTIVE, Action::STATUS_ARCHIVED],
            Action::STATUSES,
        );
    }

    public function test_the_next_due_cursor_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('actions', 'scheduled_for'));
    }

    /**
     * `nextOccurrenceAt()` has two branches: one reads a pre-loaded
     * `upcomingOccurrences` relation, the other queries fresh when the caller
     * arranged no eager load. Both must apply the same filter and the same
     * order — this is the test that would catch the two silently drifting
     * apart.
     */
    public function test_next_occurrence_at_agrees_whether_or_not_the_relation_is_eager_loaded(): void
    {
        $action = $this->action(Intention::factory()->create());

        $soonest = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->addHours(1)]);
        Occurrence::factory()->for($action)->create(['scheduled_for' => now()->addHours(2)]);
        Occurrence::factory()->for($action)->create(['scheduled_for' => now()->addHours(3)]);

        $cold = $action->nextOccurrenceAt();

        $eagerLoaded = Action::query()
            ->with('upcomingOccurrences')
            ->findOrFail($action->id)
            ->nextOccurrenceAt();

        $this->assertNotNull($cold);
        $this->assertNotNull($eagerLoaded);
        $this->assertTrue($soonest->scheduled_for->equalTo($cold));
        $this->assertTrue($cold->equalTo($eagerLoaded));
    }
}
