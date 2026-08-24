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

        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => Carbon::now()->subDays(3)->setTime(21, 30),
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertTrue($action->fresh()->scheduled_for->isFuture());
    }

    public function test_activating_leaves_anchored_actions_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_PAUSED]);

        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'recurrence' => null,
            'scheduled_for' => null,
        ]);

        app(UpdateIntention::class)->handle($intention, ['status' => Intention::STATUS_ACTIVE]);

        $this->assertNull($action->fresh()->scheduled_for);
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
        ]);

        app(UpdateIntention::class)->handle($intention, ['title' => 'Renamed']);

        $this->assertTrue($action->fresh()->scheduled_for->equalTo($stale));
    }
}
