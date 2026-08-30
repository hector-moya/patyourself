<?php

use App\Console\Commands\AlertFailedJobs;
use App\Console\Commands\FireDueActions;
use App\Console\Commands\SendReminderDigests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The trigger engine: every minute, fire any actions whose time has come.
// withoutOverlapping() prevents a slow run from racing the next minute's run;
// the engine's own guarded update is the second idempotency layer.
Schedule::command(FireDueActions::class)->everyMinute()->withoutOverlapping();

// The daily digest: every minute, send to any user whose local digest time has
// arrived and who has not had one today. Runs per-minute rather than hourly so
// each user can pick their own time in their own timezone.
// withoutOverlapping(5): a short lock. The default 1440-minute lock would strand
// a SIGKILLed run for 24h, costing every user that day's digest.
Schedule::command(SendReminderDigests::class)->everyMinute()->withoutOverlapping(5);

// The queue's own smoke alarm. Hourly is often enough to matter and rare
// enough not to nag; it sends synchronously, because an alert about a broken
// queue that is itself queued would never arrive.
// withoutOverlapping(5): a short lock, same reasoning as the digest above —
// the conditions that SIGKILL a scheduler run correlate with the conditions
// that fail jobs, so this is exactly the alarm a 24h-stranded lock would
// silence. runInBackground(): a slow synchronous SES call must not block
// schedule:run from reaching FireDueActions/SendReminderDigests behind it —
// that property is about the *process*, not the notification, which still
// sends synchronously within its own background run.
Schedule::command(AlertFailedJobs::class)->hourly()->withoutOverlapping(5)->runInBackground();
