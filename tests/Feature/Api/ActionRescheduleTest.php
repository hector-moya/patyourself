<?php

namespace Tests\Feature\Api;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PATCH /api/actions/{action}: the JSON half of the reschedule endpoint. Shares
 * its writer (RescheduleAction) and its ownership rules with the web side, and
 * answers with the action's next occasion — which only exists once the grid has
 * been materialised.
 */
class ActionRescheduleTest extends TestCase
{
    use RefreshDatabase;

    private function actionFor(User $user): Action
    {
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        return Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_ACTIVE,
            'recurrence' => 'daily',
            'series_started_at' => Carbon::parse('2026-08-23 19:00:00'),
        ]);
    }

    public function test_guests_are_unauthorized(): void
    {
        $action = $this->actionFor(User::factory()->create(['timezone' => 'UTC']));

        $this->patchJson("/api/actions/{$action->id}", [
            'kind' => 'clock',
            'time' => '06:30',
            'recurrence' => 'weekdays',
        ])->assertUnauthorized();
    }

    public function test_owner_can_reschedule_to_a_clock_recurrence(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);
        Sanctum::actingAs($user);

        $this->patchJson("/api/actions/{$action->id}", [
            'kind' => 'clock',
            'time' => '20:30',
            'recurrence' => 'daily',
        ])
            ->assertOk()
            ->assertJsonPath('id', $action->id)
            ->assertJsonPath('recurrence', 'daily')
            ->assertJsonPath('status', Action::STATUS_ACTIVE);

        $action->refresh();
        $this->assertSame('20:30', $action->series_started_at->utc()->format('H:i'));
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_the_response_reports_the_occasion_the_new_cadence_produces(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);
        Sanctum::actingAs($user);

        // The reschedule has just purged every unlogged slot ahead of now, so
        // the grid is empty when the response is built. Without materialising,
        // this field is null on every successful reschedule.
        $this->patchJson("/api/actions/{$action->id}", [
            'kind' => 'clock',
            'time' => '20:30',
            'recurrence' => 'daily',
        ])
            ->assertOk()
            ->assertJsonPath('next_occurrence_at', '2026-08-26T20:30:00.000000Z');
    }

    public function test_turning_an_action_anchored_reports_no_next_occasion(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);
        Sanctum::actingAs($user);

        // A cue-anchored action has no grid at all, so null here is the honest
        // answer rather than a symptom of an unmaterialised one.
        $this->patchJson("/api/actions/{$action->id}", [
            'kind' => 'anchored',
            'anchor' => 'after serving the first plate',
        ])
            ->assertOk()
            ->assertJsonPath('next_occurrence_at', null)
            ->assertJsonPath('recurrence', null);

        $this->assertNull($action->fresh()->series_started_at);
    }

    public function test_a_stranger_cannot_reschedule(): void
    {
        $action = $this->actionFor(User::factory()->create(['timezone' => 'UTC']));
        Sanctum::actingAs(User::factory()->create(['timezone' => 'UTC']));

        $this->patchJson("/api/actions/{$action->id}", [
            'kind' => 'clock',
            'time' => '07:00',
            'recurrence' => 'daily',
        ])->assertForbidden();
    }

    public function test_a_clock_reschedule_requires_a_valid_time(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);
        Sanctum::actingAs($user);

        $this->patchJson("/api/actions/{$action->id}", ['kind' => 'clock', 'time' => '7am'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('time');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
