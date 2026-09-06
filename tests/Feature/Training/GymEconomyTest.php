<?php

namespace Tests\Feature\Training;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\ActionLog;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use App\Services\Training\MaterialisesOccasion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a gym session is worth, and what survives.
 *
 * Two claims, and the module is only allowed to exist if both hold.
 *
 * The first is the economy. **One occasion produces exactly one
 * {@see ActionLog}**, whatever the workflow recorded against it — a forty-set
 * session is worth what a glass of water is worth. `logCount` is what the
 * companion ladder spends, so the moment a module can mint fuel by being more
 * granular than "one occasion", the app has to hold a position on what each
 * module is worth and every new module reopens the argument. Recording
 * therefore does not log: writing {@see PerformedSet} rows moves the count by
 * zero, and the verdict stays a separate press, by a person, afterwards.
 *
 * Half of that rule is the schema's, not this file's: `action_logs` carries a
 * unique index on `occurrence_id` (see
 * `2026_08_26_093410_add_occurrence_columns_to_action_logs_table.php`), so a
 * second verdict on one occasion cannot be inserted at all and no test here can
 * fail for want of it. What these guards add is the half a unique index cannot
 * state — that a module cannot buy extra fuel by minting extra *occasions* to
 * log against, and that recording at the workflow's own extension site is worth
 * nothing until a person presses the verdict.
 *
 * The second is that history does not vanish. An exercise deleted from the
 * catalogue keeps the sets performed against it — deletion hides it from new
 * templates, it does not rewrite what was already written — and a strategy
 * revision leaves the previous version's routine and sets intact and readable.
 *
 * Counts are read through {@see CompanionResolver} rather than off
 * `action_logs` alone: the table count pins the row, the resolver's `logCount`
 * pins the economy.
 */
class GymEconomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * A cue-anchored action on a loop that records through the gym workflow.
     *
     * `anchored()` pins both of ActionFactory's random fields — `recurrence`
     * and `series_started_at` — to null, so the action stands for no grid at
     * all and every occasion in these tests is one the test itself made.
     */
    private function gymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();
    }

    /** The same action on a loop with no workflow: the plain screen's case. */
    private function plainAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->anchored()
            ->create();
    }

    private function exercise(string $name): Exercise
    {
        return Exercise::factory()->create(['name' => $name]);
    }

    private function setsRecordedOn(Occurrence $occurrence): int
    {
        return PerformedSet::where('occurrence_id', $occurrence->id)->count();
    }

    /**
     * Verdicts already on the record, one per day, most recent yesterday.
     *
     * Used to give two users unequal histories before a comparison starts. With
     * both baselines at zero, comparing how far the counts moved is
     * arithmetically the same as comparing the totals, and the weaker claim is
     * exactly the one the delta form exists to avoid.
     */
    private function logPastSessions(User $user, Action $action, int $days): void
    {
        for ($day = 1; $day <= $days; $day++) {
            $occurrence = Occurrence::factory()->for($action)->create([
                'scheduled_for' => now()->subDays($day)->setTime(19, 0),
            ]);

            app(LogAction::class)->handle($user, $action, [
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ], $occurrence);
        }
    }

    public function test_forty_performed_sets_create_no_log(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise('Barbell Back Squat');

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        PerformedSet::factory()->count(40)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
        ]);

        // The forty rows really are on the record. Without this the two zeroes
        // below would be a report on an empty table.
        $this->assertSame(40, $this->setsRecordedOn($occurrence));

        // Recording is not logging. Nobody has pressed a verdict, so there is
        // no outcome — and no fuel.
        $this->assertSame(0, ActionLog::count());
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_one_session_produces_exactly_one_log_however_much_it_recorded(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise('Barbell Back Squat');
        $bench = $this->exercise('Barbell Bench Press');

        // Wednesday: a long session, two movements, twenty-four sets.
        $heavy = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->count(12)->create([
            'occurrence_id' => $heavy->id,
            'exercise_id' => $squat->id,
        ]);
        PerformedSet::factory()->count(12)->create([
            'occurrence_id' => $heavy->id,
            'exercise_id' => $bench->id,
        ]);
        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $heavy);

        // Thursday: one set, and the user went home.
        $this->travel(1)->days();
        $light = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->create([
            'occurrence_id' => $light->id,
            'exercise_id' => $squat->id,
        ]);
        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $light);

        // Two genuinely separate sessions, twenty-four times apart in how much
        // they recorded. Both halves matter: one session, or two sessions of
        // the same size, would make the counts below agree for free.
        $this->assertNotSame($heavy->id, $light->id);
        $this->assertSame(24, $this->setsRecordedOn($heavy));
        $this->assertSame(1, $this->setsRecordedOn($light));

        // One log each. These two are the unique index restating itself and
        // cannot fail while it stands; the two below are the part this test
        // actually carries — a granular module minting a second *occasion* to
        // log against would satisfy the index and still be caught here.
        $this->assertSame(1, $heavy->log()->count());
        $this->assertSame(1, $light->log()->count());
        $this->assertSame(2, ActionLog::count());
        $this->assertSame(2, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_a_failed_session_still_carries_its_sets(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $deadlift = $this->exercise('Conventional Deadlift');

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->count(5)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $deadlift->id,
        ]);

        $reason = 'Back felt wrong on the third set, so I racked it and went home.';

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ], $occurrence);

        $stored = $log->fresh();

        $this->assertSame(ActionLog::OUTCOME_FAILED, $stored->outcome);

        // Verbatim: the stated reason is the raw material the next strategy
        // version is written from.
        $this->assertSame($reason, $stored->reason);

        // The five sets that happened before it went wrong are still the
        // record. A session going badly is not a session that never happened,
        // and the sets are what the next version's plan is read against.
        $this->assertSame(5, $this->setsRecordedOn($occurrence));
        $this->assertEquals(
            [1, 2, 3, 4, 5],
            PerformedSet::where('occurrence_id', $occurrence->id)
                ->orderBy('set_number')
                ->pluck('set_number')
                ->all(),
        );

        // And a failure is worth exactly what a completion is worth: one.
        $this->assertSame(1, ActionLog::count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_blobs_counts_move_identically_for_a_gym_log_and_a_plain_one(): void
    {
        $resolver = app(CompanionResolver::class);

        $gymUser = $this->user();
        $gymAction = $this->gymAction($gymUser);

        $plainUser = $this->user();
        $plainAction = $this->plainAction($plainUser);

        // Deliberately unequal history before the comparison starts. Two users
        // both sitting at zero would make "the counts moved the same distance"
        // arithmetically identical to "the counts are the same number", which
        // is the weaker claim — a gym special case landing on the right total
        // would survive it.
        $this->logPastSessions($gymUser, $gymAction, 3);
        $this->logPastSessions($plainUser, $plainAction, 1);

        $gymBefore = $resolver->forUser($gymUser);
        $plainBefore = $resolver->forUser($plainUser);

        // The baselines really do differ, so nothing below can quietly decay
        // back into comparing totals.
        $this->assertNotSame($plainBefore->logCount, $gymBefore->logCount);

        // A real session, recorded through the workflow's own extension site
        // before the verdict is pressed: two movements, twelve sets.
        $occurrence = app(MaterialisesOccasion::class)->forAction($gymAction);
        PerformedSet::factory()->count(6)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $this->exercise('Barbell Back Squat')->id,
        ]);
        PerformedSet::factory()->count(6)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $this->exercise('Pull-Up')->id,
        ]);
        app(LogAction::class)->handle($gymUser, $gymAction, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        // The plain loop: no workflow, nothing to record, one verdict.
        app(LogAction::class)->handle($plainUser, $plainAction, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);

        $gymAfter = $resolver->forUser($gymUser);
        $plainAfter = $resolver->forUser($plainUser);

        $gymLogDelta = $gymAfter->logCount - $gymBefore->logCount;
        $plainLogDelta = $plainAfter->logCount - $plainBefore->logCount;

        // The claim is about the delta, not the number. Asserting `logCount ===
        // 1` on both would still pass if gym were special-cased in a way that
        // happened to land on 1.
        $this->assertSame($plainLogDelta, $gymLogDelta);

        // The plain loop is the reference, so it has to have moved at all —
        // otherwise two zeroes would agree and prove nothing.
        $this->assertSame(1, $plainLogDelta);

        // And the session it is being compared against was substantial, so a
        // module minting fuel per set — the one way this could ever differ —
        // had its chance to show.
        $this->assertSame(12, $this->setsRecordedOn($occurrence));

        // The other number the ladder is walked from moves together too:
        // logging is not an insight for either loop, so both stay put.
        $this->assertSame(
            $plainAfter->insightCount - $plainBefore->insightCount,
            $gymAfter->insightCount - $gymBefore->insightCount,
        );

        // Nothing is asserted about `stageIndex()` moving equally, and that is
        // deliberate: the ladder's rungs are unevenly spaced, so two users at
        // different points on it climb different distances for the same log.
        // These two counts are the only inputs the resolver walks it from —
        // pin them and the ladder follows.
    }

    /**
     * A revision supersedes the version, archives its action and authors a new
     * one. The routine written under the old version, and the sets performed
     * against it, stay exactly where they were — including for an exercise the
     * next version has no intention of prescribing again. Whether the new
     * action inherits a copy of the routine is a separate question this batch
     * does not answer; what is pinned here is that nothing is destroyed.
     */
    public function test_a_routine_survives_a_strategy_revision(): void
    {
        $this->withoutVite();

        $user = $this->user();
        $loop = Intention::factory()->for($user)->withWorkflow('gym')->create();
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
        ]);
        $loop->refresh();

        $action = Action::factory()
            ->for($loop, 'intention')
            ->anchored()
            ->create([
                'strategy_id' => $loop->activeStrategy->id,
                'status' => Action::STATUS_ACTIVE,
            ]);

        $squat = $this->exercise('Barbell Back Squat');
        $dropped = $this->exercise('Dumbbell Lateral Raise');

        // Two rows in one batch, which only works because the factory numbers
        // `position` per batch rather than hardcoding 1.
        ActionExercise::factory()
            ->count(2)
            ->sequence(
                ['exercise_id' => $squat->id],
                ['exercise_id' => $dropped->id],
            )
            ->create(['action_id' => $action->id]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->count(3)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
        ]);
        PerformedSet::factory()->count(3)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $dropped->id,
        ]);
        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        $this->actingAs($user)
            ->post(route('loops.experiments.store', $loop), [
                'intervention_point' => Strategy::POINT_CRAVING,
                'approach' => 'Name the craving out loud before opening the app.',
                'rationale' => 'The cue is unavoidable, so the cue is the wrong place to intervene.',
                'supersedes_reason' => 'Removing the cue did not survive contact with a working day.',
                'review_after_days' => 14,
                'cadence' => 'keep',
            ])
            // A rejected payload also redirects — back, with an error bag — so
            // the redirect alone says nothing about whether anything happened.
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $loop->refresh();

        // The revision actually happened. A silent no-op would leave version 1
        // standing and every assertion below would be about a loop nothing was
        // done to.
        $this->assertSame(2, $loop->activeStrategy->version);
        $this->assertNotSame($action->id, $loop->activeAction->id);

        // The prior action is archived, never deleted — `action_exercises`
        // cascades on action delete, so deleting it would take the routine with
        // it silently.
        $this->assertDatabaseHas('actions', [
            'id' => $action->id,
            'status' => Action::STATUS_ARCHIVED,
        ]);

        // The routine is still there, both rows, still in order.
        $this->assertEquals(
            [$squat->id, $dropped->id],
            ActionExercise::where('action_id', $action->id)
                ->orderBy('position')
                ->pluck('exercise_id')
                ->all(),
        );

        // And so are the sets, including the three for the movement the next
        // version drops — still readable, still naming their exercise.
        $this->assertSame(6, $this->setsRecordedOn($occurrence));
        $this->assertSame(
            3,
            PerformedSet::where('occurrence_id', $occurrence->id)
                ->where('exercise_id', $dropped->id)
                ->count(),
        );
        $this->assertSame(
            'Dumbbell Lateral Raise',
            PerformedSet::where('exercise_id', $dropped->id)->firstOrFail()->exercise->name,
        );

        // The verdict pressed under version 1 is still the only log, and still
        // counts for exactly what it did before the revision.
        $this->assertSame(1, ActionLog::count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    /**
     * Why `performed_sets.exercise_id` restricts rather than cascades: the
     * database itself refuses to let a catalogue tidy-up erase what somebody
     * lifted. Deleting an exercise hides it from new templates; it does not
     * rewrite history, and there is no path here that quietly half-does both.
     */
    public function test_deleting_an_exercise_leaves_its_performed_sets_intact(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $swing = $this->exercise('Kettlebell Swing');

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->count(3)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $swing->id,
        ]);

        // There is something to lose. The refusal below would be just as green
        // against an exercise nobody had ever used.
        $this->assertSame(3, PerformedSet::where('exercise_id', $swing->id)->count());

        $this->assertThrows(
            fn () => $swing->delete(),
            QueryException::class,
        );

        // Refused, not partly applied: the exercise is still in the catalogue
        // and all three sets still point at it.
        $this->assertTrue(Exercise::whereKey($swing->id)->exists());
        $this->assertSame(3, PerformedSet::where('exercise_id', $swing->id)->count());
        $this->assertSame(
            'Kettlebell Swing',
            PerformedSet::where('exercise_id', $swing->id)->firstOrFail()->exercise->name,
        );
    }
}
