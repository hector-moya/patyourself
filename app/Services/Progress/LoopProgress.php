<?php

namespace App\Services\Progress;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Strategy\OutcomeStreak;
use Illuminate\Support\Collection;

/**
 * Read-side aggregation for one loop's progress. Pure: no writes, no model
 * calls. Streak delegates to OutcomeStreak (the active-strategy leading run).
 *
 * Two scopes, and picking the wrong one is the mistake this class exists to
 * prevent. {@see forLoop()} spans the loop's whole lifetime, so it survives
 * strategy revisions — right for "how is this loop going overall".
 * {@see forCurrentVersion()} covers only the running version, which is what
 * any figure set against an earlier version has to be, or the comparison is
 * partly against itself. {@see previousDecidedVersion()} supplies the other
 * side of that comparison.
 *
 * `skipped` outcomes are neutral throughout — excluded from every rate, kept
 * in the recent strip. The caller eager-loads `activeStrategy` and
 * `actionLogs`.
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

        return [
            'streak' => ['outcome' => $outcome, 'length' => $length],
            'completion_rate' => $decided === 0 ? null : (int) round($completed / $decided * 100),
            'totals' => ['completed' => $completed, 'failed' => $failed, 'skipped' => $skipped],
            'recent' => $this->strip($logs),
            'last_logged_at' => $logs->max('logged_at')?->toIso8601String(),
        ];
    }

    /**
     * The active experiment's own record: streak, rate and totals since that
     * version began.
     *
     * This is the block that tells a strategy which is failing from one which
     * is working. forLoop() spans every version, so on its own a fresh
     * intervention drags the previous version's evidence forward and reads as
     * though it had inherited its record.
     *
     * `skipped` is excluded from the denominator — the occasion never
     * happened — and reported separately, so a thin sample stays visible rather
     * than hidden behind a percentage.
     *
     * @return array{
     *   version: int,
     *   started_at: string,
     *   day_of_experiment: int,
     *   planned_days: ?int,
     *   is_under_review: bool,
     *   verdict: ?string,
     *   streak: array{outcome: ?string, length: int},
     *   completion_rate: ?int,
     *   totals: array{completed: int, failed: int, skipped: int},
     *   recent: list<string>,
     *   last_logged_at: ?string,
     * }|null  Null when the loop has no active version — a perfectly good state.
     */
    public function forCurrentVersion(Intention $loop): ?array
    {
        $strategy = $loop->activeStrategy;

        if (! $strategy instanceof Strategy) {
            return null;
        }

        $logs = ActionLog::query()
            ->whereHas('action', fn ($query) => $query->where('strategy_id', $strategy->id))
            ->get(['id', 'outcome', 'logged_at']);

        $completed = $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count();
        $failed = $logs->where('outcome', ActionLog::OUTCOME_FAILED)->count();
        $skipped = $logs->where('outcome', ActionLog::OUTCOME_SKIPPED)->count();
        $decided = $completed + $failed;

        [$outcome, $length] = $this->streak->forStrategy($strategy);

        return [
            'version' => $strategy->version,
            'started_at' => $strategy->created_at->toIso8601String(),
            'day_of_experiment' => $strategy->dayOfExperiment(),
            'planned_days' => $strategy->plannedDays(),
            'is_under_review' => $strategy->isUnderReview(),
            'verdict' => $strategy->verdict,
            'streak' => ['outcome' => $outcome, 'length' => $length],
            'completion_rate' => $decided === 0 ? null : (int) round($completed / $decided * 100),
            'totals' => ['completed' => $completed, 'failed' => $failed, 'skipped' => $skipped],
            // Scoped to this version like everything else in this block. The
            // whole-loop strip in forLoop() would run back across the
            // revision, so the marks and the rate beside them would be
            // counting different things.
            'recent' => $this->strip($logs),
            'last_logged_at' => $logs->max('logged_at')?->toIso8601String(),
        ];
    }

    /**
     * The version immediately before the running one that produced a decision,
     * and the rate it held at.
     *
     * Only a version that was actually tested can be compared against — one
     * that was replaced before anything was logged has no rate, and skipping
     * over it finds the last version that does. Null when there is no such
     * version, and the caller then says nothing rather than rendering a
     * comparison against zero.
     *
     * `skipped` is excluded from the denominator here exactly as it is
     * everywhere else: the occasion never happened, so it decided nothing.
     *
     * @return array{version: int, rate: int}|null
     */
    public function previousDecidedVersion(Intention $loop): ?array
    {
        $strategy = $loop->activeStrategy;

        if (! $strategy instanceof Strategy) {
            return null;
        }

        $earlier = $loop->strategies()
            ->where('version', '<', $strategy->version)
            ->orderByDesc('version')
            ->get(['id', 'version']);

        if ($earlier->isEmpty()) {
            return null;
        }

        $totals = ActionLog::query()
            ->join('actions', 'actions.id', '=', 'action_logs.action_id')
            ->whereIn('actions.strategy_id', $earlier->pluck('id'))
            ->whereIn('action_logs.outcome', [ActionLog::OUTCOME_COMPLETED, ActionLog::OUTCOME_FAILED])
            ->get(['action_logs.outcome', 'actions.strategy_id'])
            ->groupBy('strategy_id');

        foreach ($earlier as $version) {
            $logs = $totals->get($version->id);

            if ($logs === null || $logs->isEmpty()) {
                continue;
            }

            $completed = $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count();

            return [
                'version' => $version->version,
                'rate' => (int) round($completed / $logs->count() * 100),
            ];
        }

        return null;
    }

    /**
     * The newest ten outcomes, re-ordered oldest → newest so the strip reads
     * left to right.
     *
     * @param  Collection<int, ActionLog>  $logs
     * @return list<string>
     */
    private function strip(Collection $logs): array
    {
        return $logs
            ->sortByDesc('logged_at')
            ->take(10)
            ->reverse()
            ->pluck('outcome')
            ->values()
            ->all();
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
     * @return list<array{
     *   strategy_id: int,
     *   version: int,
     *   status: string,
     *   intervention_point: string,
     *   approach: string,
     *   hypothesis: ?string,
     *   started_at: string,
     *   review_at: ?string,
     *   day_of_experiment: int,
     *   planned_days: ?int,
     *   is_under_review: bool,
     *   verdict: ?string,
     *   verdict_note: ?string,
     *   outcomes: list<array{outcome: string, reason: ?string, logged_at: string}>,
     *   totals: array{completed: int, failed: int, skipped: int},
     * }>
     */
    public function experimentsFor(Intention $loop): array
    {
        // See IntentionController::show — dayOfExperiment() reads `successor`,
        // so loading it up front keeps this one query rather than one per version.
        $strategies = $loop->strategies()->with('successor')->orderedByVersion()->get();

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
