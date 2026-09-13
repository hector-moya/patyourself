<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Carbon\CarbonImmutable;
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
            'status' => Action::STATUS_ACTIVE,
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
        $this->assertNotNull($action->series_started_at);
        $this->assertSame('06:30', $action->series_started_at->utc()->format('H:i'));
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
        $this->assertNull($action->series_started_at);
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

    /**
     * The coach could already rename an action over MCP and the owner could
     * not in the app. Renaming must reach `$action->update()` and never the
     * rescheduler, which purges future occasions.
     *
     * Killing mutation: route the title through RescheduleAction, or drop the
     * title from the request's rules. Either way the title assertion fails.
     */
    public function test_owner_can_rename_an_action(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'title' => 'Upper body 2',
        ]);

        $this->actingAs($user)
            ->patch(route('actions.update', $action), ['title' => 'Upper body A'])
            ->assertRedirect();

        $this->assertSame('Upper body A', $action->fresh()->title);
    }

    /**
     * `description` is accepted by `RescheduleActionRequest` and written by
     * the controller, but no UI posts it — the action editor has no
     * description field. Endpoint surface only, exercised directly rather
     * than through the app, so a regression here would otherwise go untested.
     *
     * Killing mutation: drop `description` from the request's rules, or from
     * the controller's `$fields` array. Either way the assertion fails.
     */
    public function test_owner_can_update_the_description(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'description' => 'Old description',
        ]);

        $this->actingAs($user)
            ->patch(route('actions.update', $action), ['description' => 'New description'])
            ->assertRedirect();

        $this->assertSame('New description', $action->fresh()->description);
    }

    /**
     * The edit form always posts the schedule, so a rename arrives carrying
     * one. Task 1's guard is what keeps the occasions; this asserts the two
     * work together over HTTP rather than only in the writer.
     */
    public function test_renaming_while_resubmitting_the_same_schedule_keeps_the_occasions(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'title' => 'Upper body 2',
            'recurrence' => 'daily',
            'series_started_at' => CarbonImmutable::parse('2026-09-14 08:00:00'),
            'metadata' => ['schedule_kind' => 'clock', 'anchor' => null],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->patch(route('actions.update', $action), [
                'title' => 'Upper body A',
                'kind' => 'clock',
                'time' => $action->series_started_at->setTimezone('Europe/London')->format('H:i'),
                'recurrence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertSame('Upper body A', $action->fresh()->title);
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * The whole safety claim, end to end, rather than proven piecemeal: the
     * server sends `time`, `recurrence` and `schedule_kind` on the loop
     * screen (`IntentionController::actionLayer`), the edit form pre-fills
     * from exactly those fields, and `RescheduleAction`'s unchanged-schedule
     * guard treats the resubmission as a no-op. The values below are read off
     * the Inertia payload rather than hardcoded, so this fails if the server
     * stops sending `time`, if the guard stops matching what the server
     * sends, or if the two derivations of a schedule ever drift apart from
     * each other — three distinct defects, none of which the writer-only or
     * fixed-value tests above can catch on their own.
     *
     * Killing mutation: drop `time` from `actionLayer()`'s payload (Item 1),
     * loosen `describesTheSameSchedule()` so it never matches, or change how
     * either side formats the clock time. Any one of those either 422s the
     * PATCH or purges the occurrence.
     */
    public function test_the_schedule_the_server_sends_round_trips_through_a_rename_without_losing_the_occurrence(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'title' => 'Upper body 2',
            'recurrence' => 'daily',
            'series_started_at' => CarbonImmutable::parse('2026-09-14 08:00:00'),
            'metadata' => ['schedule_kind' => 'clock', 'anchor' => null],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::now()->addDays(3),
        ]);

        $response = $this->actingAs($user)->get("/loops/{$loop->id}")->assertOk();

        $payload = collect($response->viewData('page')['props']['actions'])
            ->firstWhere('id', $action->id);

        $this->actingAs($user)
            ->patch(route('actions.update', $action), [
                'title' => 'Upper body A',
                'kind' => $payload['schedule_kind'],
                'time' => $payload['time'],
                'recurrence' => $payload['recurrence'],
            ])
            ->assertRedirect();

        $this->assertSame('Upper body A', $action->fresh()->title);
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * A payload changing nothing is a caller mistake, not a silent success —
     * `UpdateActionTool` already refuses one and the web endpoint matches it.
     */
    public function test_a_payload_that_changes_nothing_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();

        $this->actingAs($user)
            ->patch(route('actions.update', $action), [])
            ->assertSessionHasErrors();
    }
}
