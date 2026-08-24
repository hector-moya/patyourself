<?php

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
Schedule::command(SendReminderDigests::class)->everyMinute()->withoutOverlapping();
