<?php

namespace App\Services\Progress;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Services\Coach\OutcomeStreak;

/**
 * Read-side aggregation for one loop's progress card. Pure: no writes, no model
 * calls. Streak delegates to OutcomeStreak (the active-strategy leading run);
 * rate and totals span the loop's whole lifetime so they survive strategy
 * revisions. `skipped` outcomes are neutral — excluded from the rate, kept in
 * the recent strip. The caller eager-loads `activeStrategy` and `actionLogs`.
 */
final class LoopProgress
{
    public function __construct(private OutcomeStreak $streak) {}

    /**
     * @return array{
     *   streak: array{outcome: ?string, length: int},
     *   completion_rate: ?int,
     *   totals: array{completed: int, failed: int, skipped: int},
     *   recent: list<string>,
     *   last_logged_at: ?string,
     * }
     */
    public function forLoop(Intention $loop): array
    {
        $logs = $loop->actionLogs;

        $completed = $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count();
        $failed = $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count();
        $skipped = $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count();

        $decided = $completed + $failed;

        [$outcome, $length] = $loop->activeStrategy === null
            ? [null, 0]
            : $this->streak->forStrategy($loop->activeStrategy);

        // The newest 10 logs, re-ordered oldest → newest so the strip reads left-to-right.
        $recent = $logs
            ->sortByDesc('logged_at')
            ->take(10)
            ->reverse()
            ->pluck('outcome')
            ->values()
            ->all();

        return [
            'streak' => ['outcome' => $outcome, 'length' => $length],
            'completion_rate' => $decided === 0 ? null : (int) round($completed / $decided * 100),
            'totals' => ['completed' => $completed, 'failed' => $failed, 'skipped' => $skipped],
            'recent' => $recent,
            'last_logged_at' => $logs->max('logged_at')?->toIso8601String(),
        ];
    }
}
