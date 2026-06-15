<?php

namespace Tests\Feature\Scheduling;

use App\Events\ActionFired;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TriggerEngineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A pending action due `subMinute` ago in an active intention, unless
     * overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function dueAction(array $overrides = [], string $intentionStatus = Intention::STATUS_ACTIVE): Action
    {
        $intention = Intention::factory()->create(['status' => $intentionStatus]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        return Action::factory()->for($intention)->create(array_merge([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->subMinute(),
            'recurrence' => 'daily',
        ], $overrides));
    }

    public function test_fires_a_due_pending_action(): void
    {
        $action = $this->dueAction();

        $fired = app(TriggerEngine::class)->fireDueActions();

        $this->assertSame(1, $fired);
        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
        $this->assertNotNull($action->fresh()->metadata['fired_at']);
    }

    public function test_does_not_fire_a_future_action(): void
    {
        $action = $this->dueAction(['scheduled_for' => now()->addHour()]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_does_not_fire_an_anchored_action(): void
    {
        $action = $this->dueAction(['scheduled_for' => null, 'recurrence' => null]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_does_not_fire_when_the_intention_is_not_active(): void
    {
        $action = $this->dueAction([], Intention::STATUS_PAUSED);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
        $this->assertSame(Action::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_does_not_refire_an_already_active_action(): void
    {
        $action = $this->dueAction(['status' => Action::STATUS_ACTIVE]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueActions());
    }

    public function test_is_idempotent_across_runs(): void
    {
        $action = $this->dueAction();
        $engine = app(TriggerEngine::class);

        $this->assertSame(1, $engine->fireDueActions());
        $this->assertSame(0, $engine->fireDueActions()); // second run fires nothing
        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }

    public function test_catch_up_fires_a_stale_action_exactly_once(): void
    {
        $action = $this->dueAction(['scheduled_for' => now()->subDays(3)]);
        $engine = app(TriggerEngine::class);

        $this->assertSame(1, $engine->fireDueActions());
        $this->assertSame(0, $engine->fireDueActions()); // no backfill
        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }

    public function test_returns_the_count_of_fired_actions(): void
    {
        $this->dueAction();
        $this->dueAction();
        $this->dueAction(['scheduled_for' => now()->addHour()]); // future, not fired

        $this->assertSame(2, app(TriggerEngine::class)->fireDueActions());
    }

    public function test_firing_dispatches_action_fired_once(): void
    {
        Event::fake([ActionFired::class]);
        $action = $this->dueAction();

        app(TriggerEngine::class)->fireDueActions();

        Event::assertDispatchedTimes(ActionFired::class, 1);
        Event::assertDispatched(
            ActionFired::class,
            fn (ActionFired $event): bool => $event->action->is($action),
        );
    }

    public function test_no_fire_dispatches_no_event(): void
    {
        Event::fake([ActionFired::class]);
        // A future pending action is not due, so nothing fires.
        $this->dueAction(['scheduled_for' => now()->addHour()]);

        app(TriggerEngine::class)->fireDueActions();

        Event::assertNotDispatched(ActionFired::class);
    }
}
