<?php

namespace App\Services\Reminders;

use App\Models\Occurrence;
use Illuminate\Support\Facades\URL;

/**
 * The pair of one-click signed URLs a reminder mail can offer for an
 * occurrence: Done, and Didn't happen. Both ActionDueNotification (once) and
 * DailyDigestNotification (once per row) need the same pair built the same
 * way, so it lives in one place instead of four inlined
 * `URL::temporarySignedRoute` calls.
 *
 * `failed` is deliberately absent — see QuickLogController for why a failure
 * can never be a one-click link.
 */
final class QuickLogLinks
{
    /**
     * @return array{done: string, skipped: string}
     */
    public static function linksFor(Occurrence $occurrence): array
    {
        return [
            'done' => URL::temporarySignedRoute('occurrences.quick-log', now()->addDays(7), [
                'occurrence' => $occurrence->id,
                'outcome' => 'completed',
            ]),
            'skipped' => URL::temporarySignedRoute('occurrences.quick-log', now()->addDays(7), [
                'occurrence' => $occurrence->id,
                'outcome' => 'skipped',
            ]),
        ];
    }
}
