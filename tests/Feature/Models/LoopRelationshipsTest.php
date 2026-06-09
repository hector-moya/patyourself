<?php

namespace Tests\Feature\Models;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The loop domain model's relationships and scopes — the plumbing the strategy
 * versioning and rolling-summary logic depend on. Covered here in isolation so a
 * regression in "which row is the active one" is caught at the source, not only
 * through a controller.
 */
class LoopRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    private function strategy(Intention $intention, int $version, string $status, string $point): Strategy
    {
        return $intention->strategies()->create([
            'version' => $version,
            'status' => $status,
            'intervention_point' => $point,
            'approach' => "v{$version} approach",
            'rationale' => 'because',
            'change_reason' => Strategy::REASON_INITIAL,
        ]);
    }

    public function test_active_strategy_is_the_latest_active_version(): void
    {
        $intention = Intention::factory()->create();
        $this->strategy($intention, 1, Strategy::STATUS_SUPERSEDED, Strategy::POINT_CUE);
        $v2 = $this->strategy($intention, 2, Strategy::STATUS_ACTIVE, Strategy::POINT_RESPONSE);

        $this->assertSame($v2->id, $intention->activeStrategy->id);
        $this->assertSame(Strategy::POINT_RESPONSE, $intention->activeStrategy->intervention_point);
    }

    public function test_active_strategy_is_null_when_none_is_active(): void
    {
        $intention = Intention::factory()->create();
        $this->strategy($intention, 1, Strategy::STATUS_SUPERSEDED, Strategy::POINT_CUE);

        $this->assertNull($intention->activeStrategy);
    }

    public function test_active_action_is_the_open_action_and_skips_closed_ones(): void
    {
        $intention = Intention::factory()->create();
        Action::factory()->for($intention)->create(['status' => Action::STATUS_COMPLETED]);
        $open = Action::factory()->for($intention)->create(['status' => Action::STATUS_ACTIVE]);

        $this->assertSame($open->id, $intention->activeAction->id);
    }

    public function test_active_action_is_null_when_every_action_is_closed(): void
    {
        $intention = Intention::factory()->create();
        Action::factory()->for($intention)->create(['status' => Action::STATUS_SKIPPED]);

        $this->assertNull($intention->activeAction);
    }

    public function test_latest_summary_is_the_newest_intention_scoped_one(): void
    {
        $intention = Intention::factory()->create();

        Summary::factory()->for($intention)->create(['scope' => Summary::SCOPE_INTENTION]);
        $newest = Summary::factory()->for($intention)->create(['scope' => Summary::SCOPE_INTENTION]);
        // An account-level summary on the same loop must be ignored.
        Summary::factory()->for($intention)->userScope()->create();

        $this->assertSame($newest->id, $intention->latestSummary->id);
        $this->assertSame(Summary::SCOPE_INTENTION, $intention->latestSummary->scope);
    }

    public function test_action_logs_aggregate_across_the_loops_actions(): void
    {
        $intention = Intention::factory()->create();
        $a1 = Action::factory()->for($intention)->create();
        $a2 = Action::factory()->for($intention)->create();
        ActionLog::factory()->for($a1)->completed()->create();
        ActionLog::factory()->for($a2)->failed('overslept')->create();

        // A log on a different loop's action must not leak in.
        ActionLog::factory()->for(Action::factory()->create())->completed()->create();

        $this->assertCount(2, $intention->actionLogs);
    }

    public function test_pending_scope_returns_only_open_actions(): void
    {
        $intention = Intention::factory()->create();
        Action::factory()->for($intention)->create(['status' => Action::STATUS_PENDING]);
        Action::factory()->for($intention)->create(['status' => Action::STATUS_ACTIVE]);
        Action::factory()->for($intention)->create(['status' => Action::STATUS_COMPLETED]);
        Action::factory()->for($intention)->create(['status' => Action::STATUS_SKIPPED]);
        Action::factory()->for($intention)->create(['status' => Action::STATUS_ARCHIVED]);

        $this->assertSame(2, Action::query()->pending()->count());
    }

    public function test_failures_scope_returns_only_failed_logs(): void
    {
        $action = Action::factory()->create();
        ActionLog::factory()->for($action)->completed()->create();
        ActionLog::factory()->for($action)->failed('rain')->create();
        ActionLog::factory()->for($action)->skipped()->create();

        $failures = ActionLog::query()->failures()->get();

        $this->assertCount(1, $failures);
        $this->assertSame(ActionLog::OUTCOME_FAILED, $failures->first()->outcome);
    }

    public function test_action_logs_belong_to_the_user_who_logged_them(): void
    {
        $user = User::factory()->create();
        $action = Action::factory()->create();
        $log = ActionLog::factory()->for($action)->for($user)->completed()->create();

        $this->assertTrue($log->user->is($user));
    }
}
