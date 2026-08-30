<?php

namespace App\Services\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Mails the owner when a background job has failed since the last check.
 *
 * The digest stamps `digest_last_sent_on` straight after `notify()`, which only
 * enqueues — so an exhausted job silently costs a user that day's digest. This
 * is the mitigation the digest spec named and did not build.
 *
 * The high-water mark is a cache key rather than a new table: if the cache is
 * cleared the worst case is one duplicate alert, which is the right direction
 * to fail in. The mark only advances after the notification is actually sent,
 * so a mail failure means the next tick tries again instead of swallowing it.
 *
 * The recipient is the first registered account. This app is single-user in
 * practice and the owner is account one; a multi-user future needs an explicit
 * owner flag rather than this assumption.
 */
final readonly class FailedJobsAlert
{
    private const MARK = 'alerts.failed-jobs.high-water-mark';

    public function sendIfAny(): int
    {
        $since = Cache::get(self::MARK);
        $checkedAt = Carbon::now();

        $failures = DB::table('failed_jobs')
            ->when($since !== null, fn ($query) => $query->where('failed_at', '>', $since))
            ->orderBy('failed_at')
            ->get();

        if ($failures->isEmpty()) {
            Cache::forever(self::MARK, $checkedAt);

            return 0;
        }

        $owner = User::query()->oldest('id')->first();

        if ($owner === null) {
            return 0;
        }

        Notification::send(
            $owner,
            new FailedJobsNotification($failures->count(), $failures->last()->exception),
        );

        // Only now. If sending threw, the mark is untouched and the next tick
        // reports the same failures rather than losing them.
        Cache::forever(self::MARK, $checkedAt);

        return $failures->count();
    }
}
