<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use App\Services\Alerts\FailedJobsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class FailedJobsAlertTest extends TestCase
{
    use RefreshDatabase;

    private function recordFailure(string $uuid = 'a-uuid'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: the worker died',
            'failed_at' => now(),
        ]);
    }

    public function test_it_alerts_the_owner_when_a_job_has_failed(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $this->recordFailure();

        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(1, $reported);
        Notification::assertSentTo($owner, FailedJobsNotification::class);
    }

    public function test_it_does_not_re_alert_on_the_next_tick(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->recordFailure();

        app(FailedJobsAlert::class)->sendIfAny();
        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(0, $reported);
        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    public function test_it_says_nothing_when_no_job_has_failed(): void
    {
        Notification::fake();
        User::factory()->create();

        $this->assertSame(0, app(FailedJobsAlert::class)->sendIfAny());

        Notification::assertNothingSent();
    }

    public function test_it_does_not_advance_the_mark_when_sending_throws(): void
    {
        $owner = User::factory()->create();
        $this->recordFailure();

        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp is down'));

        try {
            app(FailedJobsAlert::class)->sendIfAny();
        } catch (\RuntimeException) {
            // expected
        }

        // The mark did not move, so the next tick still sees the failure.
        Notification::fake();
        $this->assertSame(1, app(FailedJobsAlert::class)->sendIfAny());
    }

    public function test_the_notification_sends_on_the_sync_connection(): void
    {
        // An alert about a broken queue that is itself queued is not an alert.
        $this->assertSame(
            ['mail' => 'sync'],
            (new FailedJobsNotification(1, 'RuntimeException: the worker died'))->viaConnections(),
        );
    }

    public function test_the_command_runs(): void
    {
        Notification::fake();
        User::factory()->create();
        $this->recordFailure();

        $this->artisan('jobs:alert-failed')->assertSuccessful();

        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }
}
