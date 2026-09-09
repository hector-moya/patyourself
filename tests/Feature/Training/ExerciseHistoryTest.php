<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Training\ExerciseHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The progression screen's read model: every occasion that recorded an
 * exercise, newest first, with the sets it carried.
 *
 * A pure read: no record detection, no 1RM, no volume total, no fraction of
 * one, no trend called progress.
 */
class ExerciseHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-07 19:00:00');
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /** A cue-anchored gym action: no clock time, so no grid of occasions. */
    private function anchoredGymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();
    }

    /**
     * A clock-scheduled gym action. `recurrence` and `series_started_at` are
     * pinned explicitly rather than left to the factory's random defaults.
     */
    private function clockScheduledGymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => now()->subDays(30),
            ]);
    }

    private function exercise(string $name = 'Barbell Back Squat'): Exercise
    {
        return Exercise::factory()->create(['name' => $name]);
    }

    public function test_it_returns_one_entry_per_occasion_newest_first(): void
    {
        $user = $this->user();
        $action = $this->clockScheduledGymAction($user);
        $squat = $this->exercise();

        $older = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDays(5)]);
        PerformedSet::factory()->create([
            'occurrence_id' => $older->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 40,
        ]);

        $newer = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $newer->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 8,
            'weight' => 50,
        ]);

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $this->assertSame([$newer->id, $older->id], array_column($result, 'occurrence_id'));
        $this->assertTrue($newer->scheduled_for->equalTo($result[0]['performed_at']));
        $this->assertTrue($older->scheduled_for->equalTo($result[1]['performed_at']));
    }

    public function test_sets_within_a_session_are_in_set_number_order(): void
    {
        $user = $this->user();
        $action = $this->anchoredGymAction($user);
        $squat = $this->exercise();

        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);

        // Inserted out of set_number order, so the assertion below cannot
        // pass by the sets merely happening to already be in order.
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 3,
            'reps' => 30,
            'weight' => 50,
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 10,
            'weight' => 50,
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 2,
            'reps' => 20,
            'weight' => 50,
        ]);

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $this->assertSame([10, 20, 30], array_column($result[0]['sets'], 'reps'));
    }

    public function test_a_body_weight_set_comes_back_null_not_zero(): void
    {
        $user = $this->user();
        $action = $this->anchoredGymAction($user);
        $pushup = $this->exercise('Push-Up');

        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $pushup->id,
            'set_number' => 1,
            'reps' => 20,
            'weight' => null,
        ]);

        $result = app(ExerciseHistory::class)->forExercise($pushup, $user);

        $this->assertNull($result[0]['sets'][0]['weight']);
    }

    public function test_another_users_sets_on_the_same_catalogue_exercise_are_excluded(): void
    {
        $stranger = $this->user();
        $strangerAction = $this->clockScheduledGymAction($stranger);
        $squat = $this->exercise();

        $strangerOccurrence = Occurrence::factory()->for($strangerAction)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $strangerOccurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 99,
            'weight' => 100,
        ]);

        $user = $this->user();
        $action = $this->clockScheduledGymAction($user);
        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 11,
            'weight' => 60,
        ]);

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $this->assertCount(1, $result);
        $this->assertSame([11], array_column($result[0]['sets'], 'reps'));
    }

    /**
     * `exercise_id` restricts on `performed_sets`, so an exercise carrying
     * sets can never itself be deleted — that is the schema working. What can
     * go away is the routine row that once configured it, and history must
     * not depend on that row still existing.
     */
    public function test_history_survives_the_exercise_leaving_every_routine(): void
    {
        $user = $this->user();
        $action = $this->anchoredGymAction($user);
        $squat = $this->exercise();

        $routine = ActionExercise::factory()->create([
            'action_id' => $action->id,
            'exercise_id' => $squat->id,
        ]);

        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDay()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 60,
        ]);

        $routine->delete();

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $this->assertCount(1, $result);
        $this->assertSame([5], array_column($result[0]['sets'], 'reps'));
    }

    /**
     * `StartExperiment` archives the prior action and creates a fresh one for
     * the new strategy version — it never carries the old routine forward.
     * The historical occasion's own action is untouched by the revision, so
     * this proves the read is scoped to the user rather than to whichever
     * action or strategy version currently happens to be active.
     */
    public function test_history_survives_a_strategy_revision_that_dropped_the_exercise(): void
    {
        $user = $this->user();
        $loop = Intention::factory()->for($user)->withWorkflow('gym')->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
        ]);

        $action = Action::factory()
            ->for($loop, 'intention')
            ->create([
                'strategy_id' => $strategy->id,
                'recurrence' => 'daily',
                'series_started_at' => now()->subDays(30),
                'status' => Action::STATUS_ACTIVE,
                'metadata' => ['schedule_kind' => 'clock', 'card' => ['style' => 'default']],
            ]);

        $squat = $this->exercise();
        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDays(2)]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 60,
        ]);

        $this->actingAs($user)->post(route('loops.experiments.store', $loop), [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Train earlier in the day, before the excuses show up.',
            'cadence' => 'keep',
        ])->assertRedirect();

        $loop->refresh();
        $this->assertSame(2, $loop->activeStrategy->version);

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $this->assertCount(1, $result);
        $this->assertSame([5], array_column($result[0]['sets'], 'reps'));
    }

    public function test_an_exercise_with_no_history_returns_an_empty_list(): void
    {
        $user = $this->user();
        $squat = $this->exercise();

        $this->assertSame([], app(ExerciseHistory::class)->forExercise($squat, $user));
    }

    public function test_it_costs_the_same_number_of_queries_for_ten_sessions_as_for_one(): void
    {
        $this->assertSame(
            $this->queriesReadingHistoryWith(1),
            $this->queriesReadingHistoryWith(10),
            'Reading exercise history costs more per extra session — a relation is being lazy-loaded per occasion.'
        );
    }

    /** Queries issued reading one exercise's history with `$sessions` prior occasions. */
    private function queriesReadingHistoryWith(int $sessions): int
    {
        $user = $this->user();
        $action = $this->clockScheduledGymAction($user);
        $squat = $this->exercise();

        for ($i = 0; $i < $sessions; $i++) {
            $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()->subDays($i + 1)]);
            PerformedSet::factory()->create([
                'occurrence_id' => $occurrence->id,
                'exercise_id' => $squat->id,
                'set_number' => 1,
                'reps' => 5,
                'weight' => 60,
            ]);
        }

        // The query log rather than DB::listen: a listener registered per call
        // would still be attached on the next one and double-count it.
        DB::enableQueryLog();
        DB::flushQueryLog();

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $count = count(DB::getQueryLog());

        DB::flushQueryLog();
        DB::disableQueryLog();

        // Pins that the measured call actually did the work, so a read that
        // returned early could not pass this by being cheap.
        $this->assertCount($sessions, $result);

        return $count;
    }

    /**
     * Two occasions can share a `scheduled_for` — different actions, same
     * slot — and without a tiebreaker their order is whatever the engine
     * happens to return, which differs between SQLite here and MySQL in
     * production.
     */
    public function test_two_occasions_sharing_a_scheduled_for_are_ordered_deterministically(): void
    {
        $user = $this->user();
        // Two different actions, not one — `occurrences` uniques on
        // (action_id, scheduled_for), so the same action could never carry
        // two occasions at the same instant.
        $firstAction = $this->anchoredGymAction($user);
        $secondAction = $this->anchoredGymAction($user);
        $squat = $this->exercise();
        $sharedTime = now()->subDays(3);

        $first = Occurrence::factory()->for($firstAction)->create(['scheduled_for' => $sharedTime]);
        PerformedSet::factory()->create([
            'occurrence_id' => $first->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 40,
        ]);

        $second = Occurrence::factory()->for($secondAction)->create(['scheduled_for' => $sharedTime]);
        PerformedSet::factory()->create([
            'occurrence_id' => $second->id,
            'exercise_id' => $squat->id,
            'set_number' => 1,
            'reps' => 8,
            'weight' => 50,
        ]);

        $result = app(ExerciseHistory::class)->forExercise($squat, $user);

        $this->assertSame([$second->id, $first->id], array_column($result, 'occurrence_id'));
    }
}
