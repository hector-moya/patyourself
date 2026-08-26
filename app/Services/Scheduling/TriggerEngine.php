<?php

namespace App\Services\Scheduling;

use App\Events\OccurrenceFired;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * The trigger engine: delivers the cue for each occasion whose moment has
 * arrived. Firing is idempotent — each occasion is claimed with a guarded
 * conditional update on `fired_at`, so an overlapping or repeated run fires
 * every occasion at most once. The actions:fire command runs this every minute.
 *
 * Bounded to the user's local day on purpose. Occasions never expire, so
 * without the window an outage would come back and deliver every missed cue at
 * once. A missed occasion is not a cue worth ringing later; it stays loggable,
 * quietly, on /catch-up.
 *
 * The window is per user, so this iterates users rather than running one global
 * query — midnight is not the same instant for two people.
 */
final class TriggerEngine
{
    /**
     * Fire every due, unfired, unlogged occasion inside each user's local day.
     * Returns the number actually fired (won by this run's guarded update).
     */
    public function fireDueOccurrences(): int
    {
        $fired = 0;

        User::query()
            ->whereHas('intentions', fn (Builder $query) => $query->where('status', Intention::STATUS_ACTIVE))
            ->cursor()
            ->each(function (User $user) use (&$fired): void {
                $localNow = Date::now($user->timezone ?? (string) config('app.timezone'));

                $due = Occurrence::query()
                    ->unlogged()
                    ->unfired()
                    ->where('scheduled_for', '<=', Date::now())
                    ->whereBetween('scheduled_for', [
                        $localNow->copy()->startOfDay()->utc(),
                        $localNow->copy()->endOfDay()->utc(),
                    ])
                    ->whereHas('action', fn (Builder $query) => $query
                        ->where('status', '!=', Action::STATUS_ARCHIVED)
                        ->whereHas('intention', fn (Builder $loop) => $loop
                            ->where('user_id', $user->id)
                            ->where('status', Intention::STATUS_ACTIVE)))
                    ->with('action.intention.user')
                    ->get();

                foreach ($due as $occurrence) {
                    if ($this->fire($occurrence)) {
                        $fired++;
                    }
                }
            });

        return $fired;
    }

    /**
     * Atomically claim one occasion. Returns true only for the run whose
     * guarded update actually changed the row (the fire owner); a concurrent or
     * repeated run sees 0 affected rows and returns false.
     *
     * Sets `fired_at` on the already-loaded model rather than calling
     * refresh(): refresh() only reloads top-level relations, so it would
     * silently drop the nested action.intention.user the batch query eager
     * loaded — costing SendDueNotification two extra queries per fire.
     */
    private function fire(Occurrence $occurrence): bool
    {
        $firedAt = Date::now();

        $affected = Occurrence::query()
            ->whereKey($occurrence->getKey())
            ->whereNull('fired_at')
            ->update(['fired_at' => $firedAt]);

        if ($affected === 1) {
            $occurrence->setAttribute('fired_at', $firedAt);
            $occurrence->syncOriginal();

            OccurrenceFired::dispatch($occurrence);

            return true;
        }

        return false;
    }
}
