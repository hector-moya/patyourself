<?php

namespace App\Services\Progress;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Strategy\OutcomeStreak;

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

    /**
     * One entry per strategy version, oldest first — the loop's experiment
     * ladder. Logs attribute to a version through `actions.strategy_id`, so a
     * log always belongs to the experiment that was running when it was made.
     *
     * Totals are raw counts, never a rounded rate: with a handful of logs a
     * percentage hides its own denominator, and rendering is where that
     * judgement belongs.
     *
     * @return list<array<string, mixed>>
     */
    public function experimentsFor(Intention $loop): array
    {
        $strategies = $loop->strategies()->orderedByVersion()->get();

        $logsByStrategy = ActionLog::query()
            ->join('actions', 'actions.id', '=', 'action_logs.action_id')
            ->where('actions.intention_id', $loop->id)
            ->orderBy('action_logs.logged_at')
            ->orderBy('action_logs.id')
            ->get([
                'action_logs.outcome',
                'action_logs.reason',
                'action_logs.logged_at',
                'actions.strategy_id',
            ])
            ->groupBy('strategy_id');

        return $strategies->map(function (Strategy $strategy) use ($logsByStrategy): array {
            $logs = $logsByStrategy->get($strategy->id) ?? collect();

            return [
                'strategy_id' => $strategy->id,
                'version' => $strategy->version,
                'status' => $strategy->status,
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'hypothesis' => $strategy->rationale,
                'started_at' => $strategy->created_at->toIso8601String(),
                'review_at' => $strategy->review_at?->toIso8601String(),
                'day_of_experiment' => $strategy->dayOfExperiment(),
                'planned_days' => $strategy->plannedDays(),
                'is_under_review' => $strategy->isUnderReview(),
                'verdict' => $strategy->verdict,
                'verdict_note' => $strategy->verdict_note,
                'outcomes' => $logs->map(fn (ActionLog $log): array => [
                    'outcome' => $log->outcome,
                    'reason' => $log->reason,
                    'logged_at' => $log->logged_at->toIso8601String(),
                ])->values()->all(),
                'totals' => [
                    'completed' => $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count(),
                    'failed' => $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count(),
                    'skipped' => $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count(),
                ],
            ];
        })->values()->all();
    }
}
