<?php

use App\Console\Commands\FireDueActions;
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
