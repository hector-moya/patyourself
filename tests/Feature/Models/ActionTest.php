<?php

namespace Tests\Feature\Models;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_pending_scope_and_is_open_predicate_track_unlogged_cards()
    {
        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->for($intention)->create(['version' => 1]);

        $open = Action::factory()->for($intention)->for($strategy, 'strategy')->pending()->create();
        $done = Action::factory()->for($intention)->for($strategy, 'strategy')->completed()->create();

        $this->assertTrue($open->isOpen());
        $this->assertFalse($done->isOpen());
        $this->assertEqualsCanonicalizing(
            [$open->id],
            Action::pending()->pluck('id')->all(),
        );
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
}
