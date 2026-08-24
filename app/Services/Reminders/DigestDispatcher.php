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
                $localMinutes = ($localNow->hour * 60) + $localNow->minute;

                if ($localMinutes < $this->minutesSinceMidnight($user->digest_time)) {
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

                // DailyDigestNotification is ShouldQueue, so notify() only enqueues
                // the job — it does not confirm delivery. The stamp below commits
                // right after, regardless of whether the queued send later succeeds.
                // If that job then exhausts its retries and lands in failed_jobs,
                // the stamp already prevents a retry on the next minute; the user
                // loses that day's digest. That is the accepted trade-off: it keeps
                // one slow/failing user's mail from blocking or re-sending to
                // everyone else, at the cost of no automatic recovery for a fully
                // failed job (see docs/superpowers/specs/2026-08-24-email-reminders-design.md).
                $user->forceFill(['digest_last_sent_on' => $localNow->toDateString()])->save();

                $sent++;
            });

        return $sent;
    }

    /**
     * Parses "H:i" — zero-padded or not, e.g. "07:00" or "9:00" — into
     * minutes since midnight, so the at-or-past comparison never depends on
     * the digest_time column's string padding (a plain string comparison
     * would put "9:00" after "23:59", locking that user out forever).
     */
    private function minutesSinceMidnight(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
