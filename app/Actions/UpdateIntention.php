<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;

/**
 * Updates an existing loop from validated input. Only keys present in the
 * payload are touched, so partial (PATCH-style) edits leave the rest intact.
 * The only place the manual update flow writes to the database.
 */
final readonly class UpdateIntention
{
    /**
     * @param  array<string, mixed>  $data  Validated subset of loop fields.
     */
    public function handle(Intention $intention, array $data): Intention
    {
        $fields = array_intersect_key($data, array_flip([
            'title',
            'description',
            'type',
            'status',
            'cue',
            'craving',
            'response',
            'reward',
        ]));

        $wasPaused = $intention->status === Intention::STATUS_PAUSED;

        $intention->update($fields);

        if ($wasPaused && $intention->status === Intention::STATUS_ACTIVE) {
            $this->reanchorStaleActions($intention);
        }

        return $intention;
    }

    /**
     * A loop can sit paused for days before the user activates it, leaving any
     * clock action anchored in the past — it would materialise a run of
     * occasions the user never had the chance to act on the moment the loop
     * went live. Push each one to its next real occurrence. Only genuinely
     * stale actions are touched; a future-dated one is left as the user
     * scheduled it. Anchored actions carry no clock time and are left alone.
     */
    private function reanchorStaleActions(Intention $intention): void
    {
        $timezone = $intention->user->timezone ?? (string) config('app.timezone');
        $schedule = new Schedule;
        $now = CarbonImmutable::now();

        $intention->actions()
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->whereNotNull('series_started_at')
            ->where('series_started_at', '<=', $now)
            ->get()
            ->each(function (Action $action) use ($schedule, $now, $timezone): void {
                $seriesStartedAt = $action->series_started_at->toImmutable();
                $recurrence = Recurrence::tryFromToken($action->recurrence);

                // nextAfter() re-arms a recurring action from its own stale slot, so
                // it preserves the weekday (and stays DST-correct) instead of
                // collapsing to "today or tomorrow" at the same clock time. It
                // returns null for a one-off, which firstOccurrence() then handles.
                $next = $schedule->nextAfter($seriesStartedAt, $now, $recurrence, $timezone)
                    ?? $schedule->firstOccurrence(
                        $now,
                        $seriesStartedAt->setTimezone($timezone)->format('H:i'),
                        $recurrence,
                        $timezone,
                    );

                if ($next === null) {
                    return;
                }

                // Same reasoning as RescheduleAction: the cadence restarts here,
                // so anything unlogged ahead of now belongs to the cadence being
                // left behind.
                $action->occurrences()
                    ->unlogged()
                    ->where('scheduled_for', '>', $now)
                    ->delete();

                $action->update(['series_started_at' => $next]);
            });
    }
}
