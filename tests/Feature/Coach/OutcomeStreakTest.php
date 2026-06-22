<?php

namespace Tests\Feature\Coach;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\OutcomeStreak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutcomeStreakTest extends TestCase
{
    use RefreshDatabase;

    private Strategy $strategy;

    private Action $action;

    protected function setUp(): void
    {
        parent::setUp();

        $intention = Intention::factory()->create();
        $this->strategy = Strategy::factory()->initial()->for($intention)->create();
        $this->action = Action::factory()
            ->for($intention)
            ->for($this->strategy)
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    /**
     * Append logs oldest-first; bump logged_at so order is deterministic.
     *
     * @param  array<int, array{0:string, 1?:?string}>  $outcomes  [outcome, reason?]
     */
    private function logs(array $outcomes): void
    {
        foreach ($outcomes as $i => [$outcome, $reason]) {
            ActionLog::factory()
                ->for($this->action)
                ->for($this->action->intention->user)
                ->create([
                    'outcome' => $outcome,
                    'reason' => $reason,
                    'logged_at' => now()->addMinutes($i),
                ]);
        }
    }

    public function test_no_logs_returns_null_outcome(): void
    {
        $this->assertSame([null, 0, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_two_failures_counts_a_failure_run_with_latest_reason(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'First reason'],
            [ActionLog::OUTCOME_FAILED, 'Latest reason'],
        ]);

        $this->assertSame([ActionLog::OUTCOME_FAILED, 2, 'Latest reason'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_five_completions_counts_a_completion_run(): void
    {
        $this->logs(array_fill(0, 5, [ActionLog::OUTCOME_COMPLETED, null]));

        $this->assertSame([ActionLog::OUTCOME_COMPLETED, 5, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_skips_are_ignored_and_do_not_break_a_run(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'a'],
            [ActionLog::OUTCOME_SKIPPED, null],
            [ActionLog::OUTCOME_FAILED, 'b'],
        ]);

        $this->assertSame([ActionLog::OUTCOME_FAILED, 2, 'b'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_opposite_outcome_resets_the_run(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'old'],
            [ActionLog::OUTCOME_COMPLETED, null],
            [ActionLog::OUTCOME_FAILED, 'new'],
        ]);

        // Newest non-skip run is a single failure.
        $this->assertSame([ActionLog::OUTCOME_FAILED, 1, 'new'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_streak_is_scoped_to_the_given_strategy(): void
    {
        // Logs on a DIFFERENT strategy's action must not count.
        $other = Strategy::factory()->initial()->create();
        $otherAction = Action::factory()->for($other->intention)->for($other)->create();
        ActionLog::factory()->for($otherAction)->for($otherAction->intention->user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'logged_at' => now(),
        ]);

        $this->logs([[ActionLog::OUTCOME_COMPLETED, null]]);

        $this->assertSame([ActionLog::OUTCOME_COMPLETED, 1, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_only_skips_returns_null_outcome(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_SKIPPED, null],
            [ActionLog::OUTCOME_SKIPPED, null],
        ]);

        $this->assertSame([null, 0, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_empty_reason_on_newest_failure_falls_through_to_older_reason(): void
    {
        // Logs are appended oldest-first; the newest failure has a blank reason,
        // so the run's latest *non-empty* reason should come from the older one.
        $this->logs([
            [ActionLog::OUTCOME_FAILED, 'Older real reason'],
            [ActionLog::OUTCOME_FAILED, ''],
        ]);

        $this->assertSame([ActionLog::OUTCOME_FAILED, 2, 'Older real reason'], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }

    public function test_single_failure_with_null_reason(): void
    {
        $this->logs([
            [ActionLog::OUTCOME_FAILED, null],
        ]);

        $this->assertSame([ActionLog::OUTCOME_FAILED, 1, null], app(OutcomeStreak::class)->forStrategy($this->strategy));
    }
}
