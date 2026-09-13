<?php

namespace App\Actions;

use App\Models\Action;
use App\Services\Scheduling\ReanchorsSeries;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes and persists an Action's schedule from a user edit. Clock edits
 * derive a fresh series anchor in the user's timezone; anchored edits clear
 * the schedule and record the anchor phrase. The only place a reschedule writes.
 */
final readonly class RescheduleAction
{
    public function __construct(private ReanchorsSeries $reanchor) {}

    public function handle(Action $action, string $kind, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action
    {
        $rule = $kind === 'clock' ? Recurrence::tryFromToken($recurrence) : null;

        $scheduledFor = $kind === 'clock'
            ? (new Schedule)->firstOccurrence(CarbonImmutable::now(), $time, $rule, $timezone)
            : null;

        $metadata = array_merge($action->metadata ?? [], [
            'schedule_kind' => $kind,
            'anchor' => $kind === 'anchored' ? $anchor : null,
        ]);

        // The edit form posts title and schedule behind one Save, so the
        // schedule arrives on every save — including one that only changed the
        // title. Re-anchoring then would purge future occasions for a text
        // edit, so a schedule that resolves to what the action already has is
        // no reschedule at all.
        //
        // The guard lives here rather than in the client because a client that
        // forgot to diff would delete occasions silently, and the connector
        // reaches this writer by a different route. One place, both callers.
        if ($this->describesTheSameSchedule($action, $kind, $time, $recurrence, $anchor, $timezone)) {
            return $action;
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
        DB::transaction(function () use ($action, $scheduledFor, $rule, $metadata): void {
            // Purges the grid the action is abandoning. Only unlogged future
            // slots go: anything already logged is evidence and the record is
            // append-only. Shared with ReanchorsSeries::forActions() rather
            // than repeated here — see its docblock for why the duplication
            // was reversed. Unconditional, regardless of whether the action
            // carries a series anchor: a cue-anchored action can still have a
            // stray future occurrence to drop.
            $this->reanchor->purgeAbandonedOccurrences($action, CarbonImmutable::now());

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
     * Compared on the description — kind, time of day, recurrence, anchor
     * phrase — rather than on the resolved anchor. `Schedule::firstOccurrence()`
     * resolves relative to now, so an action anchored last week that resubmits
     * its own time computes tomorrow's instant and would never match; the
     * guard would then fire only for actions rescheduled minutes ago, which is
     * the opposite of the case it exists for.
     *
     * The stored anchor is localised to compare its time of day.
     * `setTimezone()` resolves the offset in effect at that instant, so an
     * anchor set at 17:30 in summer still reads 17:30 in winter and a daylight
     * saving change does not read as an edit.
     *
     * `once` and a null recurrence are the same thing — a one-off — so the
     * submitted token goes through `Recurrence::tryFromToken()` before the
     * comparison, exactly as `handle()` does when it stores it.
     */
    private function describesTheSameSchedule(
        Action $action,
        string $kind,
        ?string $time,
        ?string $recurrence,
        ?string $anchor,
        string $timezone,
    ): bool {
        $metadata = $action->metadata ?? [];

        if (($metadata['schedule_kind'] ?? null) !== $kind) {
            return false;
        }

        if ($kind === 'anchored') {
            return ($metadata['anchor'] ?? null) === $anchor;
        }

        return $action->series_started_at?->setTimezone($timezone)->format('H:i') === $time
            && $action->recurrence === Recurrence::tryFromToken($recurrence)?->value;
    }
}
