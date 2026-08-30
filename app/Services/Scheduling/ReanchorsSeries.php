<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Moves a series' anchor forward and drops the occasions that belonged to the
 * cadence being left behind.
 *
 * Extracted from RescheduleAction and UpdateIntention, which each held their
 * own copy. That duplication was a deliberate ruling — the two call sites were
 * judged conceptually independent — reversed when a third caller (a timezone
 * change) made three copies of one rule.
 *
 * Logged occurrences are never touched: an outcome describes an occasion that
 * happened, and re-anchoring is about the future.
 */
final readonly class ReanchorsSeries
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @param  Collection<int, Action>  $actions
     */
    public function forActions(Collection $actions, string $timezone): void
    {
        $now = CarbonImmutable::now();

        $actions
            ->reject(fn (Action $action): bool => $action->series_started_at === null)
            ->each(function (Action $action) use ($now, $timezone): void {
                $seriesStartedAt = $action->series_started_at->toImmutable();
                $recurrence = Recurrence::tryFromToken($action->recurrence);

                // nextAfter() re-arms a recurring action from its own stale slot,
                // preserving the weekday and staying DST-correct instead of
                // collapsing to "today or tomorrow" at the same clock time. It
                // returns null for a one-off, which firstOccurrence() handles.
                $next = $this->schedule->nextAfter($seriesStartedAt, $now, $recurrence, $timezone)
                    ?? $this->schedule->firstOccurrence(
                        $now,
                        $seriesStartedAt->setTimezone($timezone)->format('H:i'),
                        $recurrence,
                        $timezone,
                    );

                if ($next === null) {
                    return;
                }

                $action->occurrences()
                    ->unlogged()
                    ->where('scheduled_for', '>', $now)
                    ->delete();

                $action->update(['series_started_at' => $next]);
            });
    }
}
