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

        $action->update([
            'scheduled_for' => $scheduledFor,
            'recurrence' => $rule?->value,
            'metadata' => array_filter($metadata, static fn ($value): bool => $value !== null),
        ]);

        return $action->refresh();
    }
}
