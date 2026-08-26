<?php

namespace Tests\Feature;

use App\Actions\UpdateIntention;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpdateIntentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_activating_a_paused_loop_reanchors_a_stale_clock_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $stale = Carbon::now()->subDays(3)->setTime(21, 30);
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => $stale,
            'series_started_at' => $stale,
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertTrue($action->fresh()->series_started_at->isFuture());
    }

    public function test_activating_leaves_anchored_actions_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => null,
            'scheduled_for' => null,
            'series_started_at' => null,
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertNull($action->fresh()->series_started_at);
    }

    public function test_a_plain_title_edit_does_not_touch_schedules(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $stale = Carbon::now()->subDays(3)->setTime(21, 30);
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => $stale,
            'series_started_at' => $stale,
        ]);

        app(UpdateIntention::class)->handle($intention, ['title' => 'Renamed']);

        $this->assertTrue($action->fresh()->series_started_at->equalTo($stale));
    }

    public function test_activating_a_stale_weekly_action_keeps_its_original_weekday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-21 14:00:00', 'UTC')); // a Wednesday

        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $staleSunday = Carbon::parse('2026-01-11 10:00:00', 'UTC'); // a stale Sunday
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'weekly',
            'scheduled_for' => $staleSunday,
            'series_started_at' => $staleSunday,
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $fresh = $action->fresh();
        $this->assertTrue($fresh->series_started_at->isFuture());
        $this->assertSame(Carbon::SUNDAY, $fresh->series_started_at->dayOfWeek);
        $this->assertSame('10:00', $fresh->series_started_at->format('H:i'));
    }

    public function test_activating_leaves_a_future_dated_pending_action_untouched(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $future = Carbon::now()->addDays(3)->setTime(9, 0);
        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => $future,
            'series_started_at' => $future,
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertTrue($action->fresh()->series_started_at->equalTo($future));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
