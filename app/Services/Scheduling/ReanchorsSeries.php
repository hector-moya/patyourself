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
 * RescheduleAction only shares the purge: a reschedule sets an explicit new
 * time/recurrence chosen by the user, not a roll-forward of the one being
 * replaced, so it calls purgeAbandonedOccurrences() directly rather than
 * forActions().
 *
 * Logged occurrences are never touched: an outcome describes an occasion that
 * happened, and re-anchoring is about the future.
 */
final readonly class ReanchorsSeries
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @param  Collection<int, Action>  $actions
     * @param  string  $fromTimezone  The zone the anchor was authored in.
     * @param  string  $toTimezone  The zone to re-arm the series in.
     */
    public function forActions(Collection $actions, string $fromTimezone, string $toTimezone): void
    {
        $now = CarbonImmutable::now();

        $actions
            ->reject(fn (Action $action): bool => $action->series_started_at === null)
            ->each(function (Action $action) use ($now, $fromTimezone, $toTimezone): void {
                $seriesStartedAt = $action->series_started_at->toImmutable();
                $recurrence = Recurrence::tryFromToken($action->recurrence);

                // nextAfter() re-arms a recurring action from its own stale slot,
                // preserving the weekday and staying DST-correct instead of
                // collapsing to "today or tomorrow" at the same clock time. It
                // returns null for a one-off, which firstOccurrence() handles.
                //
                // The authored local time-of-day is not persisted anywhere, so
                // the fallback recovers it by reading the old anchor back
                // through the zone it was authored in ($fromTimezone), then
                // places it in the destination zone ($toTimezone). Reading it
                // back through the destination zone instead would just relabel
                // the same absolute instant rather than move it.
                $next = $this->schedule->nextAfter($seriesStartedAt, $now, $recurrence, $toTimezone)
                    ?? $this->schedule->firstOccurrence(
                        $now,
                        $seriesStartedAt->setTimezone($fromTimezone)->format('H:i'),
                        $recurrence,
                        $toTimezone,
                    );

                if ($next === null) {
                    return;
                }

                $this->purgeAbandonedOccurrences($action, $now);

                $action->update(['series_started_at' => $next]);
            });
    }

    /**
     * Drops the occasions that belonged to the cadence being left behind. Only
     * unlogged future slots go: anything already logged is evidence and the
     * record is append-only.
     */
    public function purgeAbandonedOccurrences(Action $action, CarbonImmutable $now): void
    {
        $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>', $now)
            ->delete();
    }
}
