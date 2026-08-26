<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodaysOccasionsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function activeLoopFor(User $user): Intention
    {
        return Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
    }

    public function test_a_slot_whose_time_has_passed_is_due_now(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => null,
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertSame('due_now', $occasions->first()->due);
    }

    public function test_a_slot_later_today_is_upcoming(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => null,
        ]);

        $this->assertSame('upcoming', app(TodaysOccasions::class)->for($user)->first()->due);
    }

    public function test_yesterdays_unlogged_slot_is_not_due_today(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-23 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        // Yesterday's slot exists and stays loggable forever — but it belongs to
        // /catch-up, not to today. A missed occasion must never accumulate into
        // a backlog the notebook shows back to the user.
        $this->assertCount(1, $occasions);
        $this->assertSame(
            '2026-08-24 09:00:00',
            $occasions->first()->scheduledFor->utc()->toDateTimeString(),
        );
        $this->assertDatabaseHas('occurrences', ['scheduled_for' => '2026-08-23 09:00:00']);
    }

    public function test_a_logged_slot_is_not_due(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => null,
        ]);

        app(TodaysOccasions::class)->for($user);
        $occurrence = Occurrence::query()->where('action_id', $action->id)->sole();
        ActionLog::factory()->for($action)->for($occurrence)->create();

        $this->assertCount(0, app(TodaysOccasions::class)->for($user));
    }

    public function test_a_cue_anchored_action_unions_in(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchored = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after brushing my teeth'],
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertSame('anchored', $occasions->first()->due);
        $this->assertNull($occasions->first()->occurrence);
        $this->assertNull($occasions->first()->scheduledFor);
        $this->assertTrue($occasions->first()->action->is($anchored));
    }

    public function test_the_local_day_window_follows_the_users_timezone(): void
    {
        // Sydney is UTC+10 in August (no DST), so 2026-08-24 23:00:00Z is
        // already 2026-08-25 09:00 there. The correct local-day window in
        // UTC is [2026-08-24 14:00:00Z, 2026-08-25 13:59:59Z] — not the
        // naive UTC-calendar-day window [2026-08-24 00:00:00Z,
        // 2026-08-24 23:59:59Z] a regression that dropped the user's
        // timezone would fall back to. The two fixtures below sit on
        // opposite sides of that gap, so only the correct window passes.
        Carbon::setTestNow('2026-08-24 23:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Sydney']);
        $loop = $this->activeLoopFor($user);

        // Inside the correct Sydney window, outside the naive UTC window.
        $insideSydneyDay = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-25 02:00:00'),
            'recurrence' => null,
        ]);

        // Inside the naive UTC window, outside the correct Sydney window —
        // it is 15:00 the previous day in Sydney.
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 05:00:00'),
            'recurrence' => null,
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertTrue($occasions->first()->action->is($insideSydneyDay));
        $this->assertSame('upcoming', $occasions->first()->due);
    }

    public function test_it_excludes_paused_loops(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $paused = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);
        Action::factory()->for($paused)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
        ]);
        Action::factory()->for($paused)->create(['series_started_at' => null]);

        $this->assertCount(0, app(TodaysOccasions::class)->for($user));
    }

    public function test_it_excludes_archived_actions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'status' => Action::STATUS_ARCHIVED,
        ]);

        $this->assertCount(0, app(TodaysOccasions::class)->for($user));
    }

    public function test_it_never_returns_another_users_occasions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $stranger = User::factory()->create(['timezone' => 'UTC']);
        Action::factory()->for($this->activeLoopFor($stranger))->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        $this->assertCount(0, app(TodaysOccasions::class)->for(User::factory()->create()));
    }

    public function test_entries_are_ordered_by_time_with_anchored_last(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->activeLoopFor($user);

        Action::factory()->for($loop)->create(['series_started_at' => null, 'recurrence' => null]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 20:00:00'),
            'recurrence' => null,
        ]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => null,
        ]);

        $this->assertSame(
            ['due_now', 'upcoming', 'anchored'],
            app(TodaysOccasions::class)->for($user)->map(
                fn (TodaysOccasion $occasion): string => $occasion->due,
            )->all(),
        );
    }
}
