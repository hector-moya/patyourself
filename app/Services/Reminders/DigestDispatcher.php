<?php

namespace App\Services\Reminders;

use App\Models\User;
use App\Notifications\DailyDigestNotification;
use App\Services\Scheduling\TodaysActions;
use Illuminate\Support\Facades\Date;

/**
 * Sends each digest subscriber one email a day, at or after their chosen local
 * time, listing what is due today.
 *
 * Deliberately "at or past" rather than an exact minute match: an exact match
 * would stake the whole day's digest on one scheduler minute succeeding, and a
 * missed minute would silently cost the user that day with no error anywhere.
 * The per-local-day stamp is what caps it at one email.
 *
 * Users are isolated from each other by the queue: DailyDigestNotification is
 * ShouldQueue, so notify() only enqueues here and a delivery failure fails that
 * user's job alone rather than aborting the run for everyone behind them.
 */
class DigestDispatcher
{
    public function __construct(private readonly TodaysActions $todaysActions) {}

    /**
     * @return int the number of digests sent
     */
    public function dispatchDue(): int
    {
        $sent = 0;

        User::query()
            ->where('email_reminders', User::EMAIL_REMINDERS_DIGEST)
            ->cursor()
            ->each(function (User $user) use (&$sent): void {
                $localNow = Date::now($user->timezone ?? config('app.timezone'));

                if ($localNow->format('H:i') < $user->digest_time) {
                    return;
                }

                if ($user->digest_last_sent_on?->toDateString() === $localNow->toDateString()) {
                    return;
                }

                $actions = $this->todaysActions->for($user);

                if ($actions->isEmpty()) {
                    return;
                }

                $user->notify(new DailyDigestNotification($actions));

                // Stamped only after a successful dispatch, so a failure retries
                // on the next minute rather than silently skipping the day.
                $user->forceFill(['digest_last_sent_on' => $localNow->toDateString()])->save();

                $sent++;
            });

        return $sent;
    }
}
