<?php

namespace App\Services\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
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
 * The mark tracks `failed_jobs.id`, not a timestamp. `failed_at` is a
 * second-precision column, so a burst of failures inside one wall-clock
 * second (exactly what an outage produces) can tie with the moment the
 * previous tick wrote its mark and be silently, permanently skipped. An
 * auto-increment id has no such tie: every row gets a distinct, monotonic
 * value, so "greater than the mark" unambiguously means "not yet reported."
 *
 * On a lost mark (cache cleared, or first run ever) the service does not
 * simply seed the baseline to the current maximum id — that would silently
 * drop every unreported failure between the lost mark and now, which is
 * exactly backwards for a monitor whose whole job is not losing failures
 * quietly. Instead it falls back to the id just before a bounded recent
 * window (see RECENT_WINDOW_HOURS): anything older is treated as already
 * dealt with, but anything inside the window is reported, even if that
 * means reporting it a second time. A genuinely fresh install has nothing in
 * the window, so its first run still stays quiet.
 *
 * The recipient is the first registered account. This app is single-user in
 * practice and the owner is account one; a multi-user future needs an explicit
 * owner flag rather than this assumption.
 */
final readonly class FailedJobsAlert
{
    private const MARK = 'alerts.failed-jobs.high-water-mark';

    /**
     * How far back a lost mark still reports failures rather than staying
     * silent. Bounded so a lost mark cannot resurrect the entire historical
     * backlog — only what is genuinely recent enough that dropping it would
     * matter.
     */
    private const RECENT_WINDOW_HOURS = 24;

    public function sendIfAny(): int
    {
        $mark = Cache::get(self::MARK);

        if ($mark === null) {
            // The real mark is gone (cache cleared, or first run ever). Use
            // the recent-window backstop to decide what counts as "new" on
            // THIS tick, but persist the tight, precise `currentMaxId()` mark
            // immediately so every later tick goes back to exact id tracking
            // — this widened window applies once, not forever.
            $mark = $this->backstopMark();
            Cache::forever(self::MARK, $this->currentMaxId());
        }

        $failures = DB::table('failed_jobs')
            ->where('id', '>', $mark)
            ->orderBy('id')
            ->get();

        if ($failures->isEmpty()) {
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
        Cache::forever(self::MARK, $failures->last()->id);

        return $failures->count();
    }

    private function currentMaxId(): int
    {
        return (int) (DB::table('failed_jobs')->max('id') ?? 0);
    }

    /**
     * The mark to use when the real one is lost. Anchoring to the id of the
     * newest failure already outside the recent window (rather than to
     * `currentMaxId()`) means anything inside the window is still treated
     * as unreported — a duplicate alert in the worst case, never silence.
     */
    private function backstopMark(): int
    {
        return (int) (DB::table('failed_jobs')
            ->where('failed_at', '<=', now()->subHours(self::RECENT_WINDOW_HOURS))
            ->max('id') ?? 0);
    }
}
