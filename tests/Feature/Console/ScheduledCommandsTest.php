<?php

namespace Tests\Feature\Console;

use App\Console\Commands\AlertFailedJobs;
use App\Console\Commands\FireDueActions;
use App\Console\Commands\SendReminderDigests;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Guards the scheduled commands' mutex configuration in routes/console.php. A
 * withoutOverlapping() lock defaults to 1440 minutes (24h); for a once-daily
 * command, a SIGKILLed run stranding that lock costs every user a full day.
 */
class ScheduledCommandsTest extends TestCase
{
    private function eventFor(string $commandSignature): Event
    {
        return Collection::make(app(Schedule::class)->events())
            ->sole(fn ($event) => str_contains($event->command, "'{$commandSignature}'") || str_contains($event->command, " {$commandSignature}"));
    }

    public function test_the_digests_mutex_expires_after_five_minutes(): void
    {
        $this->assertSame(5, $this->eventFor(app(SendReminderDigests::class)->getName())->expiresAt);
    }

    public function test_the_fire_due_actions_mutex_keeps_the_default_expiry(): void
    {
        $this->assertSame(1440, $this->eventFor(app(FireDueActions::class)->getName())->expiresAt);
    }

    /**
     * A SIGKILLed run stranding the default 24h lock would silence the
     * smoke alarm for a day — and the conditions that kill a scheduler run
     * correlate with the conditions that fail jobs.
     */
    public function test_the_failed_jobs_alert_mutex_expires_after_five_minutes(): void
    {
        $this->assertSame(5, $this->eventFor(app(AlertFailedJobs::class)->getName())->expiresAt);
    }

    /**
     * A blocking SES call inside `schedule:run` would delay every command
     * behind it in that minute's run — including FireDueActions, which
     * drives the in-app cue. runInBackground() keeps the alert from
     * blocking the rest of the schedule; it does not touch the
     * notification's own synchronous-send guarantee.
     */
    public function test_the_failed_jobs_alert_runs_in_the_background(): void
    {
        $this->assertTrue($this->eventFor(app(AlertFailedJobs::class)->getName())->runInBackground);
    }
}
