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
            $this->reanchorPendingActions($intention);
        }

        return $intention;
    }

    /**
     * A loop can sit paused for days before the user activates it, leaving any
     * clock action scheduled in the past — it would fire the moment the loop went
     * live. Push each one to its next real occurrence. Anchored actions carry no
     * clock time and are left alone.
     */
    private function reanchorPendingActions(Intention $intention): void
    {
        $timezone = $intention->user->timezone ?? (string) config('app.timezone');
        $schedule = new Schedule;
        $now = CarbonImmutable::now();

        $intention->actions()
            ->where('status', Action::STATUS_PENDING)
            ->whereNotNull('scheduled_for')
            ->get()
            ->each(function (Action $action) use ($schedule, $now, $timezone): void {
                $localTime = $action->scheduled_for->setTimezone($timezone)->format('H:i');

                $action->update([
                    'scheduled_for' => $schedule->firstOccurrence(
                        $now,
                        $localTime,
                        Recurrence::tryFromToken($action->recurrence),
                        $timezone,
                    ),
                ]);
            });
    }
}
