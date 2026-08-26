<?php

namespace Tests\Feature\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\MaterialiseOccurrences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Occurrences are generated on read, never as a side effect of a write. Time is
 * frozen throughout: whether a slot has passed is the entire question here, so
 * a wall-clock-dependent count would be a flake waiting to happen.
 */
class MaterialiseOccurrencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    private function dailyAction(User $user, string $loopStatus = Intention::STATUS_ACTIVE): Action
    {
        // Four whole days back at 19:00, so anchor + 4 days is today at 19:00 —
        // already passed at the frozen 21:00. Five slots at or before now.
        $anchor = now()->subDays(4)->setTime(19, 0);

        return Action::factory()
            ->for(Intention::factory()->for($user)->state(['status' => $loopStatus]))
            ->create([
                'recurrence' => 'daily',
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);
    }

    public function test_it_materialises_every_past_slot_up_to_now(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->dailyAction($user);

        $created = app(MaterialiseOccurrences::class)->forUser($user);

        $this->assertSame(5, $created);
        $this->assertSame(5, $action->occurrences()->count());
        $this->assertSame(0, $action->occurrences()->where('scheduled_for', '>', now())->count());
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($user);

        $service = app(MaterialiseOccurrences::class);
        $service->forUser($user);

        $this->assertSame(0, $service->forUser($user));
        $this->assertSame(5, Occurrence::count());
    }

    public function test_it_leaves_an_already_logged_occasion_exactly_as_it_is(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->dailyAction($user);

        $service = app(MaterialiseOccurrences::class);
        $service->forUser($user);

        $occurrence = $action->occurrences()->orderBy('scheduled_for')->firstOrFail();
        $occurrence->log()->create([
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => 'failed',
            'reason' => 'Did not think about it at all',
            'logged_at' => now(),
        ]);

        $service->forUser($user);

        $this->assertSame(1, $occurrence->fresh()->log()->count());
        $this->assertSame(5, Occurrence::count());
    }

    public function test_a_paused_loop_does_not_materialise(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($user, Intention::STATUS_PAUSED);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
        $this->assertSame(0, Occurrence::count());
    }

    public function test_an_archived_action_does_not_materialise(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($user)->update(['status' => Action::STATUS_ARCHIVED]);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_a_one_off_action_materialises_exactly_one_slot(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subDays(3);

        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => null,
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(1, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_a_cue_anchored_action_with_no_schedule_materialises_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => null,
                'scheduled_for' => null,
                'series_started_at' => null,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_a_future_anchor_materialises_nothing_yet(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->addDays(2);

        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }

    public function test_weekly_slots_land_a_week_apart(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subWeeks(3)->setTime(19, 0);

        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'weekly',
                'scheduled_for' => $anchor,
                'series_started_at' => $anchor,
                'status' => Action::STATUS_PENDING,
            ]);

        $this->assertSame(4, app(MaterialiseOccurrences::class)->forUser($user));
        $this->assertSame(
            ['2026-08-05 19:00:00', '2026-08-12 19:00:00', '2026-08-19 19:00:00', '2026-08-26 19:00:00'],
            $action->occurrences()->orderBy('scheduled_for')->pluck('scheduled_for')
                ->map(fn ($slot): string => $slot->utc()->toDateTimeString())->all(),
        );
    }

    public function test_it_does_not_touch_another_users_loops(): void
    {
        $mine = User::factory()->create(['timezone' => 'UTC']);
        $theirs = User::factory()->create(['timezone' => 'UTC']);
        $this->dailyAction($theirs);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($mine));
        $this->assertSame(0, Occurrence::count());
    }

    public function test_for_loop_materialises_only_that_loop(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $first = $this->dailyAction($user);
        $this->dailyAction($user);

        $created = app(MaterialiseOccurrences::class)->forLoop($first->intention);

        $this->assertSame(5, $created);
        $this->assertSame(5, Occurrence::count());
    }

    public function test_for_loop_on_a_paused_loop_materialises_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->dailyAction($user, Intention::STATUS_PAUSED);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forLoop($action->intention));
    }
}
