<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RescheduleActionWebTest extends TestCase
{
    use RefreshDatabase;

    private function actionFor(User $user): Action
    {
        $intention = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        return Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
        ]);
    }

    public function test_owner_can_reschedule_to_a_clock_recurrence(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'time' => '06:30',
                'recurrence' => 'weekdays',
            ])
            ->assertRedirect();

        $action->refresh();
        $this->assertSame('weekdays', $action->recurrence);
        $this->assertNotNull($action->scheduled_for);
        $this->assertSame('06:30', $action->scheduled_for->utc()->format('H:i'));
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_owner_can_set_an_anchored_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'anchored',
                'anchor' => 'after lunch',
            ])
            ->assertRedirect();

        $action->refresh();
        $this->assertNull($action->scheduled_for);
        $this->assertNull($action->recurrence);
        $this->assertSame('after lunch', $action->metadata['anchor']);
    }

    public function test_a_stranger_cannot_reschedule(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $action = $this->actionFor($owner);

        $this->actingAs($stranger)
            ->patch("/actions/{$action->id}", ['kind' => 'clock', 'time' => '07:00', 'recurrence' => 'daily'])
            ->assertForbidden();
    }

    public function test_clock_requires_a_valid_time(): void
    {
        $user = User::factory()->create();
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", ['kind' => 'clock', 'time' => '7am', 'recurrence' => 'daily'])
            ->assertSessionHasErrors('time');
    }
}
