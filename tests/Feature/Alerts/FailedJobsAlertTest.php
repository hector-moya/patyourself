<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use App\Services\Alerts\FailedJobsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
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

        // Cold start: establishes the baseline before anything has failed.
        $this->assertSame(0, app(FailedJobsAlert::class)->sendIfAny());

        $this->recordFailure();

        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(1, $reported);
        Notification::assertSentTo($owner, FailedJobsNotification::class);
    }

    public function test_it_does_not_re_alert_on_the_next_tick(): void
    {
        Notification::fake();
        User::factory()->create();
        app(FailedJobsAlert::class)->sendIfAny(); // seed the baseline
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

    public function test_it_does_not_alert_on_pre_existing_failures_at_cold_start(): void
    {
        Notification::fake();
        User::factory()->create();

        // These failures predate the alert ever having run. A cold mark must
        // not turn into a report of the entire historical backlog.
        $this->recordFailure('pre-existing-uuid');

        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(0, $reported);
        Notification::assertNothingSent();

        // The baseline is now set at the pre-existing row, so a genuinely new
        // failure afterwards is still caught — the cold start did not just
        // suppress alerting forever.
        $this->recordFailure('genuinely-new-uuid');

        $this->assertSame(1, app(FailedJobsAlert::class)->sendIfAny());
        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    public function test_it_does_not_advance_the_mark_when_sending_throws(): void
    {
        $owner = User::factory()->create();
        app(FailedJobsAlert::class)->sendIfAny(); // seed the baseline
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

    public function test_the_notification_does_not_ride_the_queue(): void
    {
        // An alert about a broken queue that is itself queued is not an
        // alert. viaConnections() only takes effect for ShouldQueue
        // notifications, so the real guarantee is that nothing here ever
        // reaches the queue in the first place.
        Queue::fake();
        $owner = User::factory()->create();

        Notification::send($owner, new FailedJobsNotification(1, 'RuntimeException: the worker died'));

        Queue::assertNothingPushed();
    }

    public function test_the_command_runs(): void
    {
        Notification::fake();
        User::factory()->create();

        // Establish the baseline before the failure exists.
        $this->artisan('jobs:alert-failed')->assertSuccessful();

        $this->recordFailure();

        $this->artisan('jobs:alert-failed')->assertSuccessful();

        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }
}
