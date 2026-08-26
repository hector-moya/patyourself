<?php

namespace App\Actions;

use App\Models\Action;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;

/**
 * Recomputes and persists an Action's schedule from a user edit. Clock edits
 * derive a fresh UTC scheduled_for in the user's timezone; anchored edits clear
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

        // The anchor moves, so the grid ahead of it is the abandoned cadence.
        // Left in place it would render as due on a schedule the user has just
        // replaced. Only unlogged future slots go: anything already logged is
        // evidence and the record is append-only.
        $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>', CarbonImmutable::now())
            ->delete();

        $action->update([
            'scheduled_for' => $scheduledFor,
            // The anchor marks where the action's *current* cadence began, so a
            // reschedule re-anchors it. Left frozen, every future occasion
            // would materialise at the old time of day, and an action turned
            // cue-anchored would keep producing a phantom slot. Occurrences
            // already materialised are untouched.
            'series_started_at' => $scheduledFor,
            'recurrence' => $rule?->value,
            'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null),
        ]);

        return $action->refresh();
    }
}
