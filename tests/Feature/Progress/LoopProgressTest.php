<?php

namespace Tests\Feature\Progress;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Progress\LoopProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopProgressTest extends TestCase
{
    use RefreshDatabase;

    /** Build a loop with an active strategy + one action, return [loop, action]. */
    private function loopWithAction(): array
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();

        return [$loop, $action];
    }

    private function report(Intention $loop): array
    {
        $loop->load(['activeStrategy', 'latestSummary', 'actionLogs']);

        return app(LoopProgress::class)->forLoop($loop);
    }

    public function test_completion_rate_excludes_skips(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        ActionLog::factory()->for($action)->skipped()->create();

        $report = $this->report($loop);

        $this->assertSame(100, $report['completion_rate']);
        $this->assertSame(['completed' => 2, 'failed' => 0, 'skipped' => 1], $report['totals']);
    }

    public function test_completion_rate_is_rounded_share_of_decided_logs(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->count(2)->create();
        ActionLog::factory()->for($action)->failed()->create();

        $this->assertSame(67, $this->report($loop)['completion_rate']);
    }

    public function test_completion_rate_is_null_without_decided_logs(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->skipped()->count(2)->create();

        $report = $this->report($loop);

        $this->assertNull($report['completion_rate']);
        $this->assertSame(['completed' => 0, 'failed' => 0, 'skipped' => 2], $report['totals']);
    }

    public function test_streak_counts_the_active_strategys_leading_run(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->count(3)->create();

        $report = $this->report($loop);

        $this->assertSame('completed', $report['streak']['outcome']);
        $this->assertSame(3, $report['streak']['length']);
    }

    public function test_streak_ignores_logs_on_a_superseded_strategy(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $old = Strategy::factory()->for($loop)->superseded()->create(['version' => 1]);
        $active = Strategy::factory()->for($loop)->create(['version' => 2, 'status' => Strategy::STATUS_ACTIVE]);
        $oldAction = Action::factory()->for($loop)->for($old)->create();
        $newAction = Action::factory()->for($loop)->for($active)->create();
        ActionLog::factory()->for($oldAction)->completed()->count(5)->create();
        ActionLog::factory()->for($newAction)->completed()->create();

        // Streak is the active strategy's run (1), not the loop's lifetime (6).
        $this->assertSame(1, $this->report($loop)['streak']['length']);
    }

    public function test_streak_is_zero_without_an_active_strategy(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        $retired = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_RETIRED]);
        $action = Action::factory()->for($loop)->for($retired)->create();
        ActionLog::factory()->for($action)->completed()->count(2)->create();

        $report = $this->report($loop);

        $this->assertNull($report['streak']['outcome']);
        $this->assertSame(0, $report['streak']['length']);
        // Lifetime rate is still computed even with no active strategy.
        $this->assertSame(100, $report['completion_rate']);
    }

    public function test_recent_strip_is_oldest_to_newest_capped_at_ten(): void
    {
        [$loop, $action] = $this->loopWithAction();
        // 12 logs, one per day; outcomes alternate so order is observable.
        foreach (range(1, 12) as $day) {
            ActionLog::factory()->for($action)->create([
                'outcome' => $day % 2 === 0 ? ActionLog::OUTCOME_COMPLETED : ActionLog::OUTCOME_FAILED,
                'logged_at' => now()->subDays(20 - $day), // day 12 is the most recent
            ]);
        }

        $recent = $this->report($loop)['recent'];

        $this->assertCount(10, $recent);
        // Newest 10 are days 3..12; oldest-first means day 3 (odd → failed) leads,
        // day 12 (even → completed) is last.
        $this->assertSame(ActionLog::OUTCOME_FAILED, $recent[0]);
        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $recent[9]);
    }

    public function test_last_logged_at_is_the_most_recent_log(): void
    {
        [$loop, $action] = $this->loopWithAction();
        ActionLog::factory()->for($action)->completed()->create(['logged_at' => now()->subDays(2)]);
        $latest = ActionLog::factory()->for($action)->completed()->create(['logged_at' => now()->subHour()]);

        $this->assertSame(
            $latest->logged_at->toIso8601String(),
            $this->report($loop)['last_logged_at'],
        );
    }

    public function test_empty_loop_reports_zeroed_metrics(): void
    {
        [$loop] = $this->loopWithAction(); // no logs

        $report = $this->report($loop);

        $this->assertNull($report['completion_rate']);
        $this->assertSame(['completed' => 0, 'failed' => 0, 'skipped' => 0], $report['totals']);
        $this->assertSame([], $report['recent']);
        $this->assertNull($report['last_logged_at']);
        $this->assertSame(0, $report['streak']['length']);
    }
}
