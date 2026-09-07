<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * Beginning to record materialises the occasion the sets hang on, which
     * puts a cue-anchored action into the scheduled half of this list. It must
     * leave the anchored half at the same moment: two cards mean two verdicts
     * mean logCount +2 for one session, and the unique index on
     * action_logs.occurrence_id cannot stop that, because they are two
     * different occasions.
     *
     * The surviving entry has to be the scheduled one. The card posts to the
     * occurrence route, and only the scheduled entry carries the occurrence id
     * that route needs.
     */
    public function test_a_materialised_cue_anchored_action_appears_once(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchored = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after work'],
        ]);

        // The seam SessionController reaches for over HTTP, called directly so
        // this stays a test of the service rather than of the route.
        $session = app(MaterialisesOccasion::class)->forAction($anchored);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertTrue($occasions->first()->action->is($anchored));
        $this->assertSame(TodaysOccasion::DUE_NOW, $occasions->first()->due);
        $this->assertNotNull($occasions->first()->occurrence);
        $this->assertTrue($occasions->first()->occurrence->is($session));
    }

    /**
     * The exclusion is about an *unlogged* occasion, not any occasion. A
     * cue-anchored action stays offerable after it has been logged — that is
     * what ResolvesOccasionSlot's fall-through to a fresh slot is for — so once
     * today's occasion carries its verdict the anchored entry has to come back.
     * An exclusion that only asked "does an occurrence exist" would delete this
     * action from the day the moment it was answered.
     */
    public function test_a_cue_anchored_action_is_offered_again_once_its_occasion_is_logged(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchored = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => null,
            'recurrence' => null,
        ]);

        $session = app(MaterialisesOccasion::class)->forAction($anchored);
        ActionLog::factory()->for($anchored)->for($session)->create();

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertTrue($occasions->first()->action->is($anchored));
        $this->assertSame(TodaysOccasion::ANCHORED, $occasions->first()->due);
        $this->assertNull($occasions->first()->occurrence);
    }

    /**
     * The exclusion uses the same local-day window the scheduled branch does.
     * Yesterday's unlogged occasion belongs to /catch-up and the scheduled
     * branch will not return it, so an exclusion reaching outside today would
     * drop the action from both halves and lose it entirely.
     */
    public function test_a_cue_anchored_action_with_only_yesterdays_unlogged_occasion_still_appears(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchored = Action::factory()->for($this->activeLoopFor($user))->create([
            'series_started_at' => null,
            'recurrence' => null,
        ]);
        Occurrence::factory()->for($anchored)->create([
            'scheduled_for' => Carbon::parse('2026-08-23 18:00:00'),
        ]);

        $occasions = app(TodaysOccasions::class)->for($user);

        $this->assertCount(1, $occasions);
        $this->assertTrue($occasions->first()->action->is($anchored));
        $this->assertSame(TodaysOccasion::ANCHORED, $occasions->first()->due);
    }

    /**
     * The occurrence check that keeps a materialised action out of the anchored
     * half has to be one correlated subquery, not a relation read per action.
     * This service runs on the dashboard, in the digest and in the MCP tool, so
     * a per-action query here is paid three times over.
     */
    public function test_todays_list_costs_the_same_however_many_cue_anchored_actions_it_has(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $this->assertSame(
            $this->queriesBuildingTodayFrom(2),
            $this->queriesBuildingTodayFrom(6),
            "Today's list costs more per extra cue-anchored action — a relation is being read per action.",
        );
    }

    /** Queries issued building today's list from `$anchored` cue-anchored actions. */
    private function queriesBuildingTodayFrom(int $anchored): int
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->activeLoopFor($user);

        for ($index = 0; $index < $anchored; $index++) {
            $action = Action::factory()->for($loop)->create([
                'series_started_at' => null,
                'recurrence' => null,
            ]);

            // Every other one mid-session, so both branches carry rows and the
            // exclusion has something to exclude in each run.
            if ($index % 2 === 0) {
                app(MaterialisesOccasion::class)->forAction($action);
            }
        }

        // The query log rather than DB::listen: a listener registered per call
        // would still be attached on the next one and double-count it.
        DB::enableQueryLog();
        DB::flushQueryLog();

        app(TodaysOccasions::class)->for($user);

        $count = count(DB::getQueryLog());

        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
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
