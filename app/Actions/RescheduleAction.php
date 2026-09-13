<?php

namespace App\Actions;

use App\Models\Action;
use App\Services\Scheduling\ReanchorsSeries;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recomputes and persists an Action's schedule from a user edit. Clock edits
 * derive a fresh series anchor in the user's timezone; anchored edits clear
 * the schedule and record the anchor phrase. The only place a reschedule writes.
 */
final readonly class RescheduleAction
{
    public function __construct(private ReanchorsSeries $reanchor, private Schedule $schedule) {}

    public function handle(Action $action, string $kind, ?string $date, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action
    {
        // One clock read for the whole method: the guard, the past-date refusal
        // and the purge must all agree about when "now" is.
        $now = CarbonImmutable::now();

        $rule = $kind === 'clock' ? Recurrence::tryFromToken($recurrence) : null;

        $scheduledFor = $kind === 'clock'
            ? $this->schedule->anchorAt($now, $date, $time, $rule, $timezone)
            : null;

        $metadata = array_merge($action->metadata ?? [], [
            'schedule_kind' => $kind,
            'anchor' => $kind === 'anchored' ? $anchor : null,
        ]);

        // The edit form posts title and schedule behind one Save, so the
        // schedule arrives on every save — including one that only changed the
        // title. Re-anchoring then would purge future occasions for a text
        // edit, so a schedule that describes what the action already has is
        // no reschedule at all.
        //
        // The guard lives here rather than in the client because a client that
        // forgot to diff would delete occasions silently, and the connector
        // reaches this writer by a different route. One place, both callers.
        if ($this->describesTheSameSchedule($action, $kind, $date, $scheduledFor, $time, $recurrence, $anchor, $timezone, $now)) {
            return $action;
        }

        // A one-off is its date, and it has no grid to snap onto, so a moment
        // that has passed cannot be resolved into a sensible anchor the way a
        // recurring one can. Refused rather than stored, because storing it
        // materialises an occasion for a moment that is already gone.
        //
        // After the guard on purpose: a one-off whose moment has passed must
        // still be renameable, and a rename resubmits that same past schedule.
        // The guard is what lets this refusal be unconditional — it fires
        // whenever the *resolved* schedule has passed, not only when the date
        // was the field that moved.
        //
        // Unreachable without a date — firstOccurrence() cannot return a past
        // instant — and guarded on `$date` anyway so that stays true by
        // construction rather than by argument.
        if ($date !== null && $rule === null && $scheduledFor !== null && $scheduledFor->lessThanOrEqualTo($now)) {
            // Blamed on the control the owner actually moved. Changing 09:00 to
            // 10:00 on a one-off that was already in the past is refused
            // correctly, but an error on `date` points at the one field they
            // did not touch — and the date input is pre-filled, so there is
            // nothing there for them to see wrong.
            $keptItsDate = $action->series_started_at?->setTimezone($timezone)->format('Y-m-d') === $date;

            throw ValidationException::withMessages(
                $keptItsDate
                    ? ['time' => 'Pick a time that has not passed.']
                    : ['date' => 'Pick a date that has not passed.'],
            );
        }

        // Dropping the abandoned grid and moving the anchor are one act.
        //
        // Apart, a failing update leaves the occasions deleted and the anchor
        // unmoved — the old grid gone and the new one never built. Together they
        // also close the window where a concurrent materialisation rebuilds the
        // cadence being replaced: materialising is incremental, so another
        // session reading mid-transaction still sees the old rows, finds nothing
        // missing, and writes nothing. Our commit then removes them and moves
        // the anchor at the same instant.
        //
        // Not airtight: a materialisation whose read ran before the delete and
        // whose write lands after the commit can still re-create old rows.
        // Closing that needs a row lock on the per-minute materialisation path,
        // which costs more than the stale slots it would prevent.
        DB::transaction(function () use ($action, $scheduledFor, $rule, $metadata, $now): void {
            // Purges the grid the action is abandoning. Only unlogged future
            // slots go: anything already logged is evidence and the record is
            // append-only. Shared with ReanchorsSeries::forActions() rather
            // than repeated here — see its docblock for why the duplication
            // was reversed. Unconditional, regardless of whether the action
            // carries a series anchor: a cue-anchored action can still have a
            // stray future occurrence to drop.
            $this->reanchor->purgeAbandonedOccurrences($action, $now);

            $action->update([
                // The anchor marks where the action's *current* cadence began, so
                // a reschedule re-anchors it. Left frozen, every future occasion
                // would materialise at the old time of day, and an action turned
                // cue-anchored would keep producing a phantom slot. Occurrences
                // already materialised are untouched.
                'series_started_at' => $scheduledFor,
                'recurrence' => $rule?->value,
                'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null),
            ]);
        });

        return $action->refresh();
    }

    /**
     * Whether the submitted schedule describes the one the action already has.
     *
     * Two branches, because the anchor is computed two different ways.
     *
     * **Without a date** the anchor is derived from `now`, so comparing
     * resolved instants would never match: an action anchored last week that
     * resubmits its own time computes tomorrow's instant, and the guard would
     * then fire only for actions rescheduled minutes ago — the opposite of the
     * case it exists for. Compared on the description instead. The stored
     * anchor is localised to read its time of day; `setTimezone()` resolves the
     * offset in effect at that instant, so an anchor set at 17:30 in summer
     * still reads 17:30 in winter and a daylight saving change does not read as
     * an edit.
     *
     * **With a date** the computation is absolute, so the two schedules can be
     * compared on where they actually land: they are the same schedule when
     * they converge on the same next occurrence. That is stricter than a date
     * comparison in one direction and looser in the other, and both are
     * deliberate. Moving a weekly action to the Wednesday after next is a real
     * change even though the weekday is unchanged; moving it between two
     * Wednesdays that have both passed is not a change at all, because both
     * describe the same series and re-anchoring would purge and rebuild the
     * grid for something nobody could observe.
     *
     * Recurrence is compared before either branch, so both sides of the
     * convergence test walk the same grid — otherwise a weekly-to-daily change
     * could converge by accident.
     *
     * `once` and a null recurrence are the same thing — a one-off — so the
     * submitted token goes through `Recurrence::tryFromToken()` before the
     * comparison, exactly as `handle()` does when it stores it.
     */
    private function describesTheSameSchedule(
        Action $action,
        string $kind,
        ?string $date,
        ?CarbonImmutable $scheduledFor,
        ?string $time,
        ?string $recurrence,
        ?string $anchor,
        string $timezone,
        CarbonImmutable $now,
    ): bool {
        $metadata = $action->metadata ?? [];

        if (($metadata['schedule_kind'] ?? null) !== $kind) {
            return false;
        }

        if ($kind === 'anchored') {
            return ($metadata['anchor'] ?? null) === $anchor;
        }

        $rule = Recurrence::tryFromToken($recurrence);

        if ($action->recurrence !== $rule?->value) {
            return false;
        }

        if ($date === null) {
            return $action->series_started_at?->setTimezone($timezone)->format('H:i') === $time;
        }

        if ($action->series_started_at === null || $scheduledFor === null) {
            return false;
        }

        return $scheduledFor->equalTo(
            $this->schedule->onOrAfter($action->series_started_at, $now, $rule, $timezone),
        );
    }
}
