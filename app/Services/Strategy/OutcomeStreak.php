<?php

namespace App\Services\Strategy;

use App\Models\ActionLog;
use App\Models\Strategy;

/**
 * Computes the leading run of one non-skip outcome on a strategy's own action
 * logs. A passive observation with no automatic action attached — nothing
 * reads it to trigger a revision; it is surfaced for the owner to see. `skipped`
 * outcomes are removed before measuring (they neither extend nor break a run);
 * an opposite outcome breaks it. Pure read; no side effects.
 */
final class OutcomeStreak
{
    /**
     * @return array{0: ?string, 1: int, 2: ?string} [outcome, runLength, latestFailureReason]
     */
    public function forStrategy(Strategy $strategy): array
    {
        $logs = ActionLog::query()
            ->whereHas('action', static fn ($query) => $query->where('strategy_id', $strategy->id))
            ->where('outcome', '!=', ActionLog::OUTCOME_SKIPPED)
            ->orderByDesc('logged_at')
            ->orderByDesc('id')
            ->get(['id', 'outcome', 'reason']);

        $leading = $logs->first()?->outcome;

        if ($leading === null) {
            return [null, 0, null];
        }

        $run = 0;
        $reason = null;

        foreach ($logs as $log) {
            if ($log->outcome !== $leading) {
                break;
            }

            $run++;

            if ($leading === ActionLog::OUTCOME_FAILED && $reason === null && $log->reason !== null && $log->reason !== '') {
                $reason = $log->reason;
            }
        }

        return [$leading, $run, $reason];
    }
}
