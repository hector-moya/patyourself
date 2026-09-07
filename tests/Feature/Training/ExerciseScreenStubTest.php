<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `training.exercise.show` — the exercise screen: what to lift, what was
 * lifted last time, and where sets against this occasion get recorded.
 *
 * This file started as the minimal stub `ExerciseController` was, ahead of
 * task 7, so `session.tsx` could link to it through a real, generated
 * Wayfinder helper instead of a hardcoded URL. It now also pins the real
 * props shape task 7 built on top of that stub: the routine's target
 * sets/reps, the sets already recorded this occasion, `LastPerformance`'s
 * read (present or entirely absent), and the exercise's own catalogue data.
 */
class ExerciseScreenStubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    private function gymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();
    }

    private function occurrenceFor(User $user): Occurrence
    {
        return Occurrence::factory()->create(['action_id' => $this->gymAction($user)->id]);
    }

    public function test_it_renders_for_the_occurrences_owner(): void
    {
        $user = $this->user();
        $occurrence = $this->occurrenceFor($user);
        $exercise = Exercise::factory()->create();

        $this->actingAs($user)
            ->get("/occurrences/{$occurrence->id}/exercises/{$exercise->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/exercise')
                ->where('occurrence_id', $occurrence->id)
                ->where('exercise.id', $exercise->id)
            );
    }

    /**
     * The routine's own target sets/reps, the sets already recorded this
     * occasion, and the exercise's catalogue fields all reach the page.
     *
     * Killing mutation: hardcode `target_sets`/`target_reps` to null (or
     * skip the `ActionExercise` lookup entirely). The `target_sets`/
     * `target_reps` assertions below would then fail — verified by direct
     * mutation and rerun.
     */
    public function test_it_renders_the_routines_target_and_the_sets_already_recorded(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $exercise = Exercise::factory()->create([
            'name' => 'Barbell Bench Press',
            'instructions' => ['Lower to the chest under control, press to lockout.'],
        ]);
        $occurrence = Occurrence::factory()->create(['action_id' => $action->id]);

        ActionExercise::factory()->create([
            'action_id' => $action->id,
            'exercise_id' => $exercise->id,
            'target_sets' => 3,
            'target_reps' => 10,
        ]);

        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
            'set_number' => 1,
            'reps' => 10,
            'weight' => 60,
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
            'set_number' => 2,
            'reps' => 9,
            'weight' => null,
        ]);

        $this->actingAs($user)
            ->get("/occurrences/{$occurrence->id}/exercises/{$exercise->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/exercise')
                ->where('exercise.name', 'Barbell Bench Press')
                ->where('exercise.target_sets', 3)
                ->where('exercise.target_reps', 10)
                ->where('exercise.instructions', ['Lower to the chest under control, press to lockout.'])
                ->where('exercise.image_path', null)
                ->has('performed_sets', 2)
                ->where('performed_sets.0.reps', 10)
                ->where('performed_sets.0.weight', 60)
                ->where('performed_sets.1.reps', 9)
                ->where('performed_sets.1.weight', null)
            );
    }

    /**
     * `LastPerformance` returns null when there is no history for this
     * exercise, and the prop must carry that null through rather than an
     * empty or zeroed shape standing in for it.
     *
     * Killing mutation: default `last` to an empty array (`['sets' => []]`)
     * instead of passing the null straight through. `->where('last', null)`
     * would then fail — verified by direct mutation and rerun.
     */
    public function test_last_is_null_when_the_exercise_has_no_history(): void
    {
        $user = $this->user();
        $occurrence = $this->occurrenceFor($user);
        $exercise = Exercise::factory()->create();

        $this->actingAs($user)
            ->get("/occurrences/{$occurrence->id}/exercises/{$exercise->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/exercise')
                ->where('last', null)
            );
    }

    /**
     * A prior session's own sets reach the page as `last`, excluding the
     * current occasion's own (still in-progress) sets — the same exclusion
     * `LastPerformanceTest` already pins at the service level; this only
     * confirms the controller passes it through rather than re-querying and
     * reintroducing the leak.
     *
     * Killing mutation: query `PerformedSet` directly for `last` instead of
     * going through `LastPerformance::forExercise()` (e.g. the most recent
     * row across all occasions, current one included). The current
     * occasion's own in-progress set would then leak into `last` — verified
     * by direct mutation and rerun.
     */
    public function test_last_carries_the_most_recent_prior_sessions_sets(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $exercise = Exercise::factory()->create();

        $priorOccurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(2),
        ]);
        PerformedSet::factory()->create([
            'occurrence_id' => $priorOccurrence->id,
            'exercise_id' => $exercise->id,
            'set_number' => 1,
            'reps' => 10,
            'weight' => 60,
        ]);

        $current = Occurrence::factory()->create(['action_id' => $action->id]);
        PerformedSet::factory()->create([
            'occurrence_id' => $current->id,
            'exercise_id' => $exercise->id,
            'set_number' => 1,
            'reps' => 999,
            'weight' => 999,
        ]);

        $this->actingAs($user)
            ->get("/occurrences/{$current->id}/exercises/{$exercise->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/exercise')
                ->has('last.sets', 1)
                ->where('last.sets.0.reps', 10)
                ->where('last.sets.0.weight', 60)
            );
    }

    /**
     * Killing mutation: remove `Gate::authorize('log', $occurrence)` from
     * `ExerciseController::show`. A stranger's occasion would then render
     * instead of refusing — verified by direct mutation and rerun.
     */
    public function test_another_users_occurrence_is_refused(): void
    {
        $owner = $this->user();
        $occurrence = $this->occurrenceFor($owner);
        $exercise = Exercise::factory()->create();

        $intruder = $this->user();

        $this->actingAs($intruder)
            ->get("/occurrences/{$occurrence->id}/exercises/{$exercise->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $occurrence = $this->occurrenceFor($this->user());
        $exercise = Exercise::factory()->create();

        $this->get("/occurrences/{$occurrence->id}/exercises/{$exercise->id}")
            ->assertRedirect('/login');
    }
}
