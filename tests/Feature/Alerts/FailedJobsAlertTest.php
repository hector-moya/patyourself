<?php

namespace Tests\Feature\Alerts;

use App\Models\User;
use App\Notifications\FailedJobsNotification;
use App\Services\Alerts\FailedJobsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FailedJobsAlertTest extends TestCase
{
    use RefreshDatabase;

    private function recordFailure(string $uuid = 'a-uuid', ?\DateTimeInterface $failedAt = null): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: the worker died',
            'failed_at' => $failedAt ?? now(),
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

        // This failure predates the alert ever having run, and is well
        // outside the recent window a lost mark falls back to. A cold mark
        // must not turn into a report of the entire historical backlog.
        $this->recordFailure('pre-existing-uuid', now()->subDays(2));

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

    /**
     * Pins the fix for the docblock's false claim: on a lost mark (a cleared
     * cache, e.g. `cache:clear` during troubleshooting), any failure inside
     * the recent window must still be reported — a duplicate alert, never
     * silence. Before this fix, a lost mark re-seeded to the current maximum
     * id and returned 0, dropping every unreported failure between the lost
     * mark and now.
     */
    public function test_a_lost_mark_reports_a_recent_failure_instead_of_going_silent(): void
    {
        Notification::fake();
        User::factory()->create();

        app(FailedJobsAlert::class)->sendIfAny(); // cold start: establishes the baseline

        $this->recordFailure('never-reported-before-the-cache-was-lost');

        // Simulate `cache:clear`: the high-water mark is gone, but the
        // failure above was never reported.
        Cache::flush();

        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(1, $reported);
        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    /**
     * The recent-window fallback bounds how far a lost mark reaches back —
     * it must not resurrect the entire historical backlog either, only
     * what is genuinely recent.
     */
    public function test_a_lost_mark_does_not_resurrect_failures_from_before_the_recent_window(): void
    {
        Notification::fake();
        User::factory()->create();

        $this->recordFailure('old-failure', now()->subDays(3));
        Cache::flush();

        $reported = app(FailedJobsAlert::class)->sendIfAny();

        $this->assertSame(0, $reported);
        Notification::assertNothingSent();
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

    /**
     * The narrower regression the recent-window fix introduced: on a LOST
     * mark specifically, the backstop must only ever be used as a query
     * bound, never persisted before a send is confirmed. Persisting it
     * eagerly (the bug) means a thrown send on the recovery tick still
     * advances the mark to `currentMaxId()`, so the next tick sees nothing
     * newer and the recovered failure is dropped forever — the exact
     * silent-loss mode this whole fix exists to prevent, reintroduced in a
     * narrower branch. `test_it_does_not_advance_the_mark_when_sending_throws`
     * does not catch this: it seeds the baseline first, so it only exercises
     * the steady-state branch, never the lost-mark one.
     */
    public function test_a_lost_mark_still_reports_after_a_send_failure_on_the_recovery_tick(): void
    {
        User::factory()->create();

        app(FailedJobsAlert::class)->sendIfAny(); // cold start: establishes the baseline
        $this->recordFailure('never-reported-before-the-cache-was-lost');

        // Simulate `cache:clear`: the high-water mark is gone.
        Cache::flush();

        // The recovery tick's send fails — a transient SES outage, exactly
        // the kind of infrastructure trouble that prompts an operator to
        // start clearing caches in the first place.
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('smtp is down'));

        try {
            app(FailedJobsAlert::class)->sendIfAny();
        } catch (\RuntimeException) {
            // expected
        }

        // The mark must not have advanced past the never-reported failure.
        Notification::fake();
        $this->assertSame(1, app(FailedJobsAlert::class)->sendIfAny());
        Notification::assertSentTimes(FailedJobsNotification::class, 1);
    }

    /**
     * The same ordering guarantee, for the other early-return on a lost
     * mark: no owner yet. The mark must not advance just because the
     * backlog has nowhere to be sent yet.
     */
    public function test_a_lost_mark_does_not_advance_when_there_is_no_owner_yet(): void
    {
        $this->recordFailure('never-reported-before-the-cache-was-lost');
        Cache::flush();

        $this->assertSame(0, app(FailedJobsAlert::class)->sendIfAny());

        // Once an owner exists, the same failure must still be reportable.
        Notification::fake();
        $owner = User::factory()->create();
        $this->assertSame(1, app(FailedJobsAlert::class)->sendIfAny());
        Notification::assertSentTo($owner, FailedJobsNotification::class);
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
