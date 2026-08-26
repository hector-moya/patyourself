<?php

namespace Tests\Feature\Scheduling;

use App\Events\OccurrenceFired;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TriggerEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-24 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * An occasion due three hours ago today, on an active loop owned by a UTC
     * user, unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function dueOccurrence(
        array $overrides = [],
        string $intentionStatus = Intention::STATUS_ACTIVE,
        string $actionStatus = Action::STATUS_ACTIVE,
    ): Occurrence {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $intention = Intention::factory()->for($user)->create(['status' => $intentionStatus]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => $actionStatus,
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        return Occurrence::factory()->for($action)->create(array_merge([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ], $overrides));
    }

    public function test_it_fires_a_due_unfired_occasion(): void
    {
        $occurrence = $this->dueOccurrence();

        $this->assertSame(1, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNotNull($occurrence->fresh()->fired_at);
    }

    public function test_it_does_not_fire_a_slot_later_today(): void
    {
        $occurrence = $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNull($occurrence->fresh()->fired_at);
    }

    public function test_it_does_not_fire_an_occasion_from_an_earlier_day(): void
    {
        $occurrence = $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-21 09:00:00')]);

        // The cue for a three-day-old occasion is not worth delivering now. An
        // outage must not produce a burst of stale cues on recovery; the
        // occasion stays loggable on /catch-up, silently.
        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNull($occurrence->fresh()->fired_at);
    }

    public function test_it_does_not_refire_an_already_fired_occasion(): void
    {
        $this->dueOccurrence(['fired_at' => Carbon::parse('2026-08-24 09:01:00')]);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_does_not_fire_an_occasion_that_already_carries_an_outcome(): void
    {
        $occurrence = $this->dueOccurrence();
        ActionLog::factory()->for($occurrence->action)->for($occurrence)->create();

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_does_not_fire_when_the_loop_is_not_active(): void
    {
        $this->dueOccurrence([], Intention::STATUS_PAUSED);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_does_not_fire_an_archived_action(): void
    {
        $this->dueOccurrence([], Intention::STATUS_ACTIVE, Action::STATUS_ARCHIVED);

        $this->assertSame(0, app(TriggerEngine::class)->fireDueOccurrences());
    }

    public function test_it_is_idempotent_across_runs(): void
    {
        $this->dueOccurrence();
        $engine = app(TriggerEngine::class);

        $this->assertSame(1, $engine->fireDueOccurrences());
        $this->assertSame(0, $engine->fireDueOccurrences());
    }

    public function test_the_window_follows_the_users_timezone(): void
    {
        Carbon::setTestNow('2026-08-24 23:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Sydney']);
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($intention)->create([
            'series_started_at' => Carbon::parse('2026-08-24 22:00:00'),
            'recurrence' => 'daily',
        ]);
        // 22:00 UTC is 08:00 on the 25th in Sydney — inside that user's today,
        // even though it is "yesterday" in UTC.
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 22:00:00'),
        ]);

        $this->assertSame(1, app(TriggerEngine::class)->fireDueOccurrences());
        $this->assertNotNull($occurrence->fresh()->fired_at);
    }

    public function test_firing_dispatches_occurrence_fired_once(): void
    {
        Event::fake([OccurrenceFired::class]);
        $occurrence = $this->dueOccurrence();

        app(TriggerEngine::class)->fireDueOccurrences();

        Event::assertDispatchedTimes(OccurrenceFired::class, 1);
        Event::assertDispatched(
            OccurrenceFired::class,
            fn (OccurrenceFired $event): bool => $event->occurrence->is($occurrence),
        );
    }

    public function test_no_fire_dispatches_no_event(): void
    {
        Event::fake([OccurrenceFired::class]);
        $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        app(TriggerEngine::class)->fireDueOccurrences();

        Event::assertNotDispatched(OccurrenceFired::class);
    }

    public function test_it_returns_the_count_fired(): void
    {
        $this->dueOccurrence();
        $this->dueOccurrence();
        $this->dueOccurrence(['scheduled_for' => Carbon::parse('2026-08-24 20:00:00')]);

        $this->assertSame(2, app(TriggerEngine::class)->fireDueOccurrences());
    }
}
