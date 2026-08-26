<?php

namespace Tests\Feature\Reminders;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\DailyDigestNotification;
use App\Services\Reminders\DigestDispatcher;
use App\Services\Scheduling\TodaysOccasion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DigestDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function userWithDueAction(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'timezone' => 'UTC',
            'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            'digest_time' => '07:00',
        ], $attributes));

        Action::factory()
            ->for(Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]))
            ->create(['series_started_at' => null, 'recurrence' => null]);

        return $user;
    }

    public function test_sends_the_digest_once_the_users_local_time_reaches_their_digest_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertSentTo($user, DailyDigestNotification::class);
        $this->assertSame('2026-08-24', $user->fresh()->digest_last_sent_on->toDateString());
    }

    public function test_does_not_send_before_the_digest_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 06:59:00');

        $user = $this->userWithDueAction();

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertNothingSentTo($user);
        $this->assertNull($user->fresh()->digest_last_sent_on);
    }

    public function test_still_sends_when_the_run_is_late(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 11:30:00');

        $user = $this->userWithDueAction();

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertSentTo($user, DailyDigestNotification::class);
    }

    public function test_uses_the_users_own_timezone_not_utc(): void
    {
        Notification::fake();
        // 12:00 UTC is 22:00 in Sydney (past 07:00) and 04:00 in Los Angeles (before it).
        Carbon::setTestNow('2026-08-24 12:00:00');

        $sydney = $this->userWithDueAction(['timezone' => 'Australia/Sydney']);
        $losAngeles = $this->userWithDueAction(['timezone' => 'America/Los_Angeles']);

        app(DigestDispatcher::class)->dispatchDue();

        Notification::assertSentTo($sydney, DailyDigestNotification::class);
        Notification::assertNothingSentTo($losAngeles);
    }

    public function test_does_not_send_twice_in_the_same_local_day(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();

        app(DigestDispatcher::class)->dispatchDue();

        Carbon::setTestNow('2026-08-24 09:00:00');

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertSentToTimes($user, DailyDigestNotification::class, 1);
    }

    public function test_sends_again_the_next_day(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();
        app(DigestDispatcher::class)->dispatchDue();

        Carbon::setTestNow('2026-08-25 07:00:00');

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertSentToTimes($user, DailyDigestNotification::class, 2);
    }

    public function test_skips_users_with_nothing_due_and_does_not_stamp_them(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = User::factory()->create([
            'timezone' => 'UTC',
            'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            'digest_time' => '07:00',
        ]);

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertNothingSentTo($user);
        $this->assertNull($user->fresh()->digest_last_sent_on);
    }

    public function test_skips_users_who_are_off_or_on_every_cue(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $off = $this->userWithDueAction(['email_reminders' => User::EMAIL_REMINDERS_OFF]);
        $everyCue = $this->userWithDueAction(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertNothingSentTo($off);
        Notification::assertNothingSentTo($everyCue);
    }

    public function test_the_command_runs_the_dispatcher(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 07:00:00');

        $user = $this->userWithDueAction();

        $this->artisan('reminders:digest')->assertSuccessful();

        Notification::assertSentTo($user, DailyDigestNotification::class);
    }

    public function test_respects_the_users_configured_digest_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 10:00:00');

        $user = $this->userWithDueAction(['digest_time' => '19:00']);

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertNothingSentTo($user);

        Carbon::setTestNow('2026-08-24 19:00:00');

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertSentTo($user, DailyDigestNotification::class);
    }

    public function test_handles_a_non_zero_padded_digest_time(): void
    {
        Notification::fake();
        Carbon::setTestNow('2026-08-24 08:00:00');

        $user = $this->userWithDueAction(['digest_time' => '9:00']);

        $this->assertSame(0, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertNothingSentTo($user);

        Carbon::setTestNow('2026-08-24 09:00:00');

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());
        Notification::assertSentTo($user, DailyDigestNotification::class);
    }

    public function test_the_digest_email_lists_actions_and_links_to_the_app(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create([
            'title' => 'Read before bed',
            'status' => Intention::STATUS_ACTIVE,
        ]);

        $scheduledFor = CarbonImmutable::parse('2026-08-24 07:30:00', 'UTC');

        $scheduled = Action::factory()->for($loop)->create([
            'title' => 'Read ten pages',
            'series_started_at' => $scheduledFor,
            'recurrence' => null,
        ]);
        $scheduled->loadMissing('intention');

        $cueAnchored = Action::factory()->for($loop)->create([
            'title' => 'Stretch',
            'series_started_at' => null,
            'recurrence' => null,
        ]);
        $cueAnchored->loadMissing('intention');

        $occasions = collect([
            new TodaysOccasion(
                action: $scheduled,
                occurrence: null,
                scheduledFor: $scheduledFor,
                due: TodaysOccasion::UPCOMING,
            ),
            new TodaysOccasion(
                action: $cueAnchored,
                occurrence: null,
                scheduledFor: null,
                due: TodaysOccasion::ANCHORED,
            ),
        ]);

        $mail = (new DailyDigestNotification($occasions))
            ->toMail($user)
            ->render();

        $this->assertStringContainsString('Read ten pages', $mail);
        $this->assertStringContainsString('Read before bed', $mail);
        $this->assertStringContainsString('7:30am', $mail);
        $this->assertStringContainsString('Stretch', $mail);
        $this->assertStringContainsString('when the cue happens', $mail);
        $this->assertStringContainsString(route('dashboard'), $mail);
    }

    public function test_the_digest_lists_a_cue_anchored_action_without_a_time(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create([
            'timezone' => 'UTC',
            'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            'digest_time' => '07:00',
        ]);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => null,
            'recurrence' => null,
            'title' => 'Put the snacks out of sight tonight',
        ]);

        Notification::fake();

        $this->assertSame(1, app(DigestDispatcher::class)->dispatchDue());

        Notification::assertSentTo($user, DailyDigestNotification::class);
    }
}
