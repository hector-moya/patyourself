<?php

namespace Tests\Feature\Actions;

use App\Actions\PersistAuthoredIntention;
use App\Actions\RescheduleAction;
use App\Actions\StartExperiment;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Authoring\AuthoredAction;
use App\Services\Authoring\AuthoredIntention;
use App\Services\Authoring\AuthoredStrategy;
use App\Services\Scheduling\MaterialiseOccurrences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * `series_started_at` is where an action's current cadence began, and it is
 * what materialisation walks forward from. `scheduled_for` cannot do that job
 * because it rolls forward on every log, so every place that writes an action
 * has to set the anchor too — otherwise that action's occasions never
 * materialise and it silently drops out of every check-in.
 */
class SeriesAnchorTest extends TestCase
{
    use RefreshDatabase;

    private function authoredLoop(?AuthoredAction $action): AuthoredIntention
    {
        return new AuthoredIntention(
            title: 'Eating to 80%',
            description: null,
            type: Intention::TYPE_BREAK,
            cue: 'Plate is empty and there is food left in the pan',
            craving: 'The taste is still there and stopping feels like waste',
            response: 'Serve a second plate',
            reward: 'A few more minutes of the taste',
            confidence: null,
            tags: [],
            strategy: new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CUE,
                approach: 'Put the pan back on the stove before sitting down',
            ),
            model: 'none',
            action: $action,
        );
    }

    public function test_a_loop_created_through_the_authoring_path_anchors_its_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $loop = app(PersistAuthoredIntention::class)->handle($user, $this->authoredLoop(
            new AuthoredAction(
                title: 'Put the pan back on the stove before sitting down',
                description: null,
                kind: 'clock',
                time: '19:00',
                recurrence: 'daily',
                anchor: null,
            ),
        ));

        $action = $loop->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->isFuture());
    }

    public function test_a_new_experiment_anchors_the_action_it_proposes(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        Action::factory()->for($loop)->for($current)->create(['status' => Action::STATUS_ACTIVE]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            null,
            null,
            new AuthoredAction(
                title: 'Name the craving out loud before serving',
                description: null,
                kind: 'clock',
                time: '19:00',
                recurrence: 'daily',
                anchor: null,
            ),
        );

        $action = $next->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->isFuture());
    }

    public function test_an_experiment_that_inherits_the_prior_cadence_still_anchors(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        $priorSlot = now()->addHours(3)->startOfSecond();
        Action::factory()->for($loop)->for($current)->create([
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => $priorSlot,
            'recurrence' => 'daily',
        ]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        );

        $action = $next->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->equalTo($priorSlot));
    }

    public function test_an_experiment_that_inherits_a_running_cadence_rolls_the_anchor_forward(): void
    {
        Carbon::setTestNow('2026-08-26 21:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        Action::factory()->for($loop)->for($current)->create([
            'status' => Action::STATUS_ACTIVE,
            // A loop that has been running for two months: the anchor is where
            // the cadence *began*, not where it is due next.
            'series_started_at' => Carbon::parse('2026-06-27 08:00:00'),
            'recurrence' => 'daily',
        ]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        );

        $action = $next->actions()->firstOrFail();

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->greaterThanOrEqualTo(now()));

        app(MaterialiseOccurrences::class)->forLoop($loop->fresh());

        // The revision inherits the cadence, not the backlog. Materialising the
        // whole historical grid behind a brand-new action would fabricate a run
        // of missed occasions for days the user actually completed.
        $this->assertSame(0, $action->occurrences()->where('scheduled_for', '<', now())->count());
    }

    public function test_an_inherited_anchor_keeps_its_time_of_day(): void
    {
        Carbon::setTestNow('2026-08-26 21:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        Action::factory()->for($loop)->for($current)->create([
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => Carbon::parse('2026-08-20 08:00:00'),
            'recurrence' => 'daily',
        ]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        );

        $action = $next->actions()->firstOrFail();

        // Rolling forward preserves the phase: 08:00 stays 08:00, it does not
        // collapse to whatever o'clock the revision happened to be started at.
        $this->assertSame('08:00', $action->series_started_at->setTimezone('UTC')->format('H:i'));
        $this->assertSame('2026-08-27', $action->series_started_at->setTimezone('UTC')->toDateString());
    }

    public function test_rescheduling_to_a_new_time_re_anchors_the_series(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subWeek()->startOfSecond();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['series_started_at' => $anchor, 'recurrence' => 'daily']);

        app(RescheduleAction::class)->handle($action, 'clock', null, '19:30', 'daily', null, 'UTC');

        $fresh = $action->fresh();

        $this->assertFalse($fresh->series_started_at->equalTo($anchor));
        $this->assertTrue($fresh->series_started_at->isFuture());
    }

    public function test_turning_an_action_cue_anchored_clears_the_series_anchor(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subWeek()->startOfSecond();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['series_started_at' => $anchor, 'recurrence' => 'daily']);

        app(RescheduleAction::class)->handle($action, 'anchored', null, null, null, 'after dinner', 'UTC');

        $fresh = $action->fresh();

        $this->assertNull($fresh->series_started_at);
    }

    /**
     * The purge is unconditional on the shape of the action being replaced —
     * a cue-anchored action carries no series anchor, but it can still have a
     * stray unlogged future occasion (e.g. left over from before it became
     * anchored), and rescheduling it must still drop that grid.
     */
    public function test_rescheduling_a_cue_anchored_action_purges_unlogged_future_occasions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->anchored()->create();

        $future = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 21:00:00'),
        ]);

        app(RescheduleAction::class)->handle($action, 'anchored', null, null, null, 'after washing up', 'UTC');

        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_rescheduling_purges_unlogged_future_occasions(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $future = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 21:00:00'),
        ]);

        app(RescheduleAction::class)->handle($action, 'clock', null, '07:00', 'daily', null, 'UTC');

        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_rescheduling_leaves_past_occasions_alone(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-20 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $past = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-22 09:00:00'),
        ]);

        app(RescheduleAction::class)->handle($action, 'clock', null, '07:00', 'daily', null, 'UTC');

        $this->assertDatabaseHas('occurrences', ['id' => $past->id]);
    }

    public function test_rescheduling_never_deletes_a_logged_occasion(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $logged = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 21:00:00'),
        ]);
        ActionLog::factory()->for($action)->for($logged)->create();

        app(RescheduleAction::class)->handle($action, 'clock', null, '07:00', 'daily', null, 'UTC');

        // The record is append-only. A future slot that already carries an
        // outcome is evidence, not a phantom.
        $this->assertDatabaseHas('occurrences', ['id' => $logged->id]);
    }

    /**
     * Starts the next experiment on a loop whose action already runs on the
     * given cadence, and returns the action the revision inherited.
     */
    private function revisionInheriting(string $anchor, ?string $recurrence): Action
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $current = Strategy::factory()->for($loop)->create(['status' => Strategy::STATUS_ACTIVE, 'version' => 1]);
        Action::factory()->for($loop)->for($current)->create([
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => Carbon::parse($anchor),
            'recurrence' => $recurrence,
        ]);

        $next = app(StartExperiment::class)->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: Strategy::POINT_CRAVING,
                approach: 'Name the craving out loud before serving',
            ),
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        );

        return $next->actions()->firstOrFail();
    }

    /**
     * A one-off has no next slot to roll to — `nextAfter()` returns null for a
     * null recurrence — so the inherited anchor used to fall back to the prior
     * one, which is in the past. That materialises exactly one unlogged
     * occasion behind now: a phantom miss on /catch-up for an occasion the new
     * experiment never asked for.
     */
    public function test_an_inherited_one_off_cadence_leaves_no_phantom_occasion(): void
    {
        Carbon::setTestNow('2026-08-26 21:00:00');

        $action = $this->revisionInheriting('2026-06-27 08:00:00', null);

        $this->assertNotNull($action->series_started_at);
        $this->assertTrue($action->series_started_at->greaterThanOrEqualTo(now()));
        // Same clock time, pushed to the next day it can happen.
        $this->assertSame('08:00', $action->series_started_at->setTimezone('UTC')->format('H:i'));

        app(MaterialiseOccurrences::class)->forLoop($action->intention);

        $this->assertSame(0, $action->occurrences()->where('scheduled_for', '<', now())->count());
    }

    public function test_an_inherited_weekly_cadence_keeps_its_weekday(): void
    {
        Carbon::setTestNow('2026-08-26 21:00:00');

        $anchor = Carbon::parse('2026-06-03 08:00:00');
        $action = $this->revisionInheriting($anchor->toDateTimeString(), 'weekly');

        // Asserted as the invariant rather than a hand-computed date: weekly
        // means the same weekday, however many weeks it has to advance.
        $this->assertSame($anchor->dayOfWeek, $action->series_started_at->dayOfWeek);
        $this->assertTrue($action->series_started_at->greaterThanOrEqualTo(now()));
    }

    /**
     * Weekdays proves less than weekly does, and deliberately so.
     *
     * `nextAfter()` and `firstOccurrence()` converge for this cadence — both
     * preserve the clock time and both skip the weekend — so no fixture can
     * tell a phase-preserving roll from one recomputed off `now`. The weekly
     * test carries that proof, via the weekday it has to keep. What is left
     * worth asserting here is the cadence's own promise: a weekdays action
     * never anchors on a Saturday or a Sunday.
     */
    public function test_an_inherited_weekdays_cadence_never_lands_on_a_weekend(): void
    {
        Carbon::setTestNow('2026-08-26 21:00:00');

        $action = $this->revisionInheriting('2026-06-05 08:00:00', 'weekdays');

        $this->assertFalse($action->series_started_at->isWeekend());
        $this->assertTrue($action->series_started_at->greaterThanOrEqualTo(now()));
        $this->assertSame('08:00', $action->series_started_at->setTimezone('UTC')->format('H:i'));
    }

    /**
     * The purge and the re-anchor are one act. Without a transaction, an
     * update that throws leaves the occasions already deleted and the anchor
     * unmoved — the abandoned grid gone and the new one never built.
     */
    public function test_rescheduling_rolls_the_purge_back_when_the_re_anchor_fails(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-08-24 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $future = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-24 21:00:00'),
        ]);

        Action::updating(function (): void {
            throw new RuntimeException('the re-anchor failed');
        });

        try {
            app(RescheduleAction::class)->handle($action, 'clock', null, '07:00', 'daily', null, 'UTC');
            $this->fail('Expected the re-anchor to throw.');
        } catch (RuntimeException) {
            // Expected.
        } finally {
            // The dispatcher is shared process-wide. Laravel's per-test app
            // reboot happens to clear it, but this test should not depend on
            // that to avoid throwing inside an unrelated one.
            Action::flushEventListeners();
        }

        $this->assertDatabaseHas('occurrences', ['id' => $future->id]);
        $this->assertSame(
            '2026-08-24 09:00:00',
            $action->fresh()->series_started_at->toDateTimeString(),
        );
    }

    /**
     * The edit form posts the schedule on every save, including a save that
     * only changed the title. Re-anchoring then would delete occasions the
     * user never asked to lose, so a schedule that still describes what the
     * action already has must do nothing at all — not re-anchor, not purge.
     *
     * Killing mutation: drop the early return in RescheduleAction::handle().
     * The occurrence below is unlogged and in the future, which is exactly
     * what purgeAbandonedOccurrences() removes, so the assertion fails.
     */
    public function test_rescheduling_to_the_same_schedule_changes_nothing(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();

        $action = Action::factory()->for($loop)->for($strategy)->create([
            'series_started_at' => Carbon::parse('2026-08-24 08:00:00'),
            'recurrence' => 'daily',
            'metadata' => ['schedule_kind' => 'clock', 'anchor' => null],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-27 09:00:00'),
        ]);

        $anchorBefore = $action->series_started_at;

        $rescheduled = app(RescheduleAction::class)->handle(
            $action,
            'clock',
            null,
            $action->series_started_at->setTimezone('Europe/London')->format('H:i'),
            'daily',
            null,
            'Europe/London',
        );

        $this->assertTrue(
            $anchorBefore->equalTo($rescheduled->series_started_at),
            'An unchanged schedule must not move the series anchor.',
        );
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * The same no-op guard applies to a cue-anchored action: resubmitting its
     * own anchor phrase is not a reschedule, so the purge must not run.
     *
     * Killing mutation: drop the early return in RescheduleAction::handle().
     * The occurrence below is unlogged and in the future, which is exactly
     * what purgeAbandonedOccurrences() removes, so the assertion fails.
     */
    public function test_rescheduling_an_anchored_action_to_the_same_phrase_changes_nothing(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $action = Action::factory()->anchored()->create();

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-08-27 09:00:00'),
        ]);

        app(RescheduleAction::class)->handle($action, 'anchored', null, null, null, 'after brushing my teeth', 'UTC');

        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * 2026-09-14 is a Monday; 2026-09-09, 2026-09-16, 2026-09-23 and
     * 2026-09-30 are the Wednesdays around it. The weekday assertions are
     * self-documenting anchors.
     */
    public function test_a_future_start_date_anchors_the_series_to_it(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-14 07:00:00'),
            'recurrence' => 'daily',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-23', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-23 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($rescheduled->series_started_at->isWednesday());
    }

    /**
     * A date names a day. Picking a Wednesday that has gone means the next
     * Wednesday, not a back-dated series — which would mint occasions nobody
     * was asked about, every one of them landing on /catch-up.
     *
     * Starts from a cue-anchored action so the result is observable: an action
     * already on the same weekly grid would converge with the guard and
     * correctly change nothing.
     */
    public function test_a_past_start_date_snaps_forward_onto_its_own_grid(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->anchored()->create();

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-09', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-16 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('occurrences', ['action_id' => $action->id]);
    }

    /**
     * The guard from the frozen amendable-action-layer spec §3, re-pinned under
     * the date branch. The edit form posts the schedule on every save, so a
     * pure rename resubmits the action's own anchor date — which is in the past
     * for any running weekly action.
     *
     * Killing mutation: drop the date branch from describesTheSameSchedule().
     * The occurrence below is unlogged and in the future, which is exactly what
     * purgeAbandonedOccurrences() removes.
     */
    public function test_resubmitting_a_past_anchor_date_unchanged_changes_nothing(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-09-16 07:30:00'),
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-09', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-09 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * Moving the start to a later date on the same weekday is a real change —
     * skipping a week — and must not read as "unchanged".
     *
     * Killing mutation: compare only time, recurrence and kind, as the guard
     * did before this branch existed. The action then keeps its old anchor and
     * the edit silently does nothing.
     */
    public function test_moving_the_start_to_a_later_date_reschedules(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-09-16 07:30:00'),
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-30', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-30 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('occurrences', ['action_id' => $action->id]);
    }

    /**
     * Two different past Wednesdays describe the same weekly series, so moving
     * between them changes nothing observable and must not purge and rebuild
     * the grid.
     *
     * Killing mutation: compare the submitted date string against the stored
     * anchor's date instead of comparing where the two schedules land. The
     * dates differ, so the guard misses and the occurrence below is purged.
     */
    public function test_a_different_past_date_on_the_same_grid_changes_nothing(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-09-16 07:30:00'),
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-02', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-09 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * Changing only the time on an action whose anchor has passed re-anchors
     * forward rather than leaving the series in the past. Refusing this was the
     * alternative design and it would have rejected an ordinary edit.
     */
    public function test_changing_only_the_time_on_a_past_anchor_moves_it_forward(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-09', '08:00', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-16 08:00:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($rescheduled->series_started_at->greaterThan(Carbon::now()));
    }

    /** A one-off has no grid to snap onto, so a date that has passed is refused. */
    public function test_a_one_off_cannot_be_moved_to_a_date_that_has_passed(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-20 09:00:00'),
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $this->expectException(ValidationException::class);

        app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-10', '09:00', 'once', null, 'UTC',
        );
    }

    /**
     * The refusal above sits *after* the unchanged-schedule guard, so a one-off
     * whose date has already passed can still be renamed — the save resubmits
     * its own date and returns before the refusal is reached.
     *
     * Killing mutation: move the past-date refusal above the guard. This test
     * then throws.
     */
    public function test_a_one_off_resubmitting_its_own_past_date_is_a_no_op(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-10 09:00:00'),
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-10', '09:00', 'once', null, 'UTC',
        );

        $this->assertSame('2026-09-10 09:00:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
