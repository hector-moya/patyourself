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
                $anchor = $this->rebase($action->series_started_at->toImmutable(), $fromTimezone, $toTimezone);
                $recurrence = Recurrence::tryFromToken($action->recurrence);

                // nextAfter() re-arms a recurring action from its own stale slot,
                // preserving the weekday and staying DST-correct instead of
                // collapsing to "today or tomorrow" at the same clock time. It
                // returns null for a one-off, which firstOccurrence() handles.
                // Both now see the rebased anchor, so an already-stale
                // recurring action moves with the rest — see rebase().
                $next = $this->schedule->nextAfter($anchor, $now, $recurrence, $toTimezone)
                    ?? $this->schedule->firstOccurrence(
                        $now,
                        $anchor->format('H:i'),
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

    /**
     * Carries an anchor's authored local date and wall-clock time across a
     * zone change, so both nextAfter() and firstOccurrence() operate on a
     * slot that already reads correctly in the destination zone.
     *
     * The authored local date and time are not persisted anywhere — only the
     * absolute instant is — so they are recovered by reading the anchor back
     * through the zone it was authored in ($fromTimezone), then that exact
     * date and time is reinterpreted as a moment in the destination zone
     * ($toTimezone). The date must come from $fromTimezone, not from the
     * instant's own (UTC-backed) representation: 07:00 Monday Brisbane is
     * 21:00 *Sunday* in London, and reading the date off the wrong side would
     * silently shift a weekly habit's weekday.
     *
     * An identity when $fromTimezone and $toTimezone are the same zone —
     * reading a local time back through its own zone and reinterpreting it in
     * that same zone reconstructs the original instant exactly — which is
     * what keeps RescheduleAction and UpdateIntention's own re-anchor calls,
     * where the two zones are always identical, unchanged by this.
     */
    private function rebase(CarbonImmutable $anchor, string $fromTimezone, string $toTimezone): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $anchor->setTimezone($fromTimezone)->format('Y-m-d H:i:s'),
            $toTimezone,
        );
    }
}
