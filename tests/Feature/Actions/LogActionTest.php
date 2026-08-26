<?php

namespace Tests\Feature\Actions;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Logging an outcome against the occasion it describes. The rule that matters
 * most here: an outcome never touches the action row, whether it is logged
 * against today's live slot or catches up an older occasion.
 */
class LogActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    private function recurringAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'scheduled_for' => now()->setTime(19, 0),
                'series_started_at' => now()->subDays(5)->setTime(19, 0),
                'status' => Action::STATUS_ACTIVE,
            ]);
    }

    public function test_logging_an_occurrence_attaches_the_outcome_to_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(3)->setTime(19, 0),
        ]);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Ate standing up, second plate before I noticed',
        ], $occurrence);

        $this->assertSame($occurrence->id, $log->occurrence_id);
        $this->assertSame($action->id, $log->action_id);
        $this->assertSame('Ate standing up, second plate before I noticed', $log->reason);
    }

    public function test_a_catch_up_log_does_not_move_the_next_due_pointer(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $nextDue = $action->scheduled_for;
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(3)->setTime(19, 0),
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        $fresh = $action->fresh();

        $this->assertTrue($fresh->scheduled_for->equalTo($nextDue));
        $this->assertSame(Action::STATUS_ACTIVE, $fresh->status);
    }

    public function test_logging_the_live_slot_no_longer_rolls_the_action_forward(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $scheduledFor = $action->scheduled_for;
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => $action->scheduled_for,
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        $fresh = $action->fresh();

        $this->assertSame(Action::STATUS_ACTIVE, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->equalTo($scheduledFor));
    }

    public function test_the_series_anchor_never_moves_when_an_outcome_is_logged(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $anchor = $action->series_started_at;

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertTrue($action->fresh()->series_started_at->equalTo($anchor));
    }

    public function test_a_caller_that_passes_no_occurrence_still_gets_one(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        // No occurrence has been materialised for this action, so there is no
        // unlogged slot to resolve inside today's window: it falls through to
        // one stamped now.
        $log = app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertNotNull($log->occurrence_id);
        $this->assertTrue($log->occurrence->scheduled_for->equalTo(now()));
    }

    public function test_a_cue_anchored_action_gets_an_occasion_stamped_now(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['recurrence' => null, 'scheduled_for' => null, 'series_started_at' => null]);

        $log = app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertNotNull($log->occurrence_id);
        $this->assertSame('2026-08-26 21:00:00', $log->occurrence->scheduled_for->utc()->toDateTimeString());
    }

    public function test_a_second_log_on_an_already_logged_slot_records_as_its_own_occasion(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        // A failure leaves the action open on the same slot, so the next log
        // resolves to that same live slot — it must not collide with the first.
        $first = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Did not think about it at all',
        ]);
        $second = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Second plate again',
        ]);

        $this->assertNotSame($first->occurrence_id, $second->occurrence_id);
        $this->assertSame(2, ActionLog::count());
    }

    public function test_it_stores_context_and_context_fields(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Kept going past full',
            'context' => 'Standing at the bench, plate refilled straight away',
            'context_fields' => ['place' => 'kitchen', 'with_others' => false, 'preceded_by' => 'skipped lunch'],
        ]);

        $this->assertSame('Standing at the bench, plate refilled straight away', $log->context);
        $this->assertSame('kitchen', $log->context_fields['place']);
    }

    public function test_the_reason_is_stored_exactly_as_it_was_given(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);
        $reason = "  didn't Think about it AT ALL.  ";

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ]);

        $this->assertSame($reason, ActionLog::firstOrFail()->reason);
    }

    public function test_logging_never_writes_to_the_action_row(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
        ]);
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        $before = $action->fresh()->toArray();

        app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
            $occurrence,
        );

        // The action is the standing prescription. Completing one occasion of it
        // says nothing about the prescription itself.
        $this->assertSame($before, $action->fresh()->toArray());
    }

    public function test_completing_a_recurring_occasion_leaves_tomorrows_slot_alone(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
            $occurrence,
        );

        $this->assertSame(
            Carbon::parse('2026-08-24 09:00:00')->toDateTimeString(),
            $action->fresh()->series_started_at->toDateTimeString(),
        );
    }

    public function test_the_live_slot_is_todays_unlogged_occasion(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);
        $yesterday = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-23 09:00:00'),
        ]);
        $today = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 09:00:00'),
        ]);

        $log = app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
        );

        // A card logs today, never a missed day. Catching up an older occasion
        // is what /catch-up and log-outcome are for, and both name the occasion.
        $this->assertSame($today->id, $log->occurrence_id);
        $this->assertNull($yesterday->fresh()->log);
    }

    public function test_an_anchored_action_stamps_its_occasion_now(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->create([
            'series_started_at' => null,
            'recurrence' => null,
        ]);

        $log = app(LogAction::class)->handle(
            $user,
            $action,
            ['outcome' => ActionLog::OUTCOME_COMPLETED],
        );

        $this->assertSame(
            '2026-08-24 12:00:00',
            $log->occurrence->scheduled_for->utc()->toDateTimeString(),
        );
    }
}
