<?php

namespace App\Actions;

use App\Models\Action;
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
            // Only unlogged future slots go: anything already logged is evidence
            // and the record is append-only.
            $action->occurrences()
                ->unlogged()
                ->where('scheduled_for', '>', CarbonImmutable::now())
                ->delete();

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
}
