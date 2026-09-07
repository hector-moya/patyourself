<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\PerformedSet;
use App\Models\User;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The routine surface: the gym workflow's config extension site, edited.
 *
 * An action's routine is a list of {@see ActionExercise} rows — one per
 * exercise it prescribes — and this file pins the three writes a person
 * makes to that list (add, reorder, remove) plus the two refusals that keep
 * it honest: an exercise has to be one the user can actually see, and the
 * action has to be theirs.
 */
class RoutineTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * A cue-anchored action on a loop that records through the gym workflow.
     *
     * `anchored()` pins both of ActionFactory's random fields — `recurrence`
     * and `series_started_at` — to null, so nothing here depends on which
     * values the factory would otherwise have rolled.
     */
    private function gymAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();
    }

    private function exercise(string $name = 'Barbell Back Squat'): Exercise
    {
        return Exercise::factory()->create(['name' => $name]);
    }

    /**
     * Killing mutation: hardcode `position` to a constant (e.g. always 1)
     * instead of computing the next open slot. The second POST in this loop
     * then collides with the unique index on (action_id, position) and the
     * request 500s instead of redirecting — verified by direct mutation and
     * rerun, captured in the task report.
     */
    public function test_adding_exercises_assigns_contiguous_unique_positions(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise('Barbell Back Squat');
        $row = $this->exercise('Barbell Row');
        $press = $this->exercise('Overhead Press');

        foreach ([$squat, $row, $press] as $exercise) {
            $this->actingAs($user)
                ->post(route('actions.exercises.store', $action), [
                    'exercise_id' => $exercise->id,
                    'target_sets' => 3,
                    'target_reps' => 8,
                ])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
        }

        $this->assertSame(
            [1, 2, 3],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('position')->all(),
        );
        $this->assertSame(
            [$squat->id, $row->id, $press->id],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('exercise_id')->all(),
        );
    }

    /**
     * Killing mutation: make `reorder` a no-op (never touch `position`). The
     * routine then stays in insertion order and the first assertion below
     * fails — verified by direct mutation and rerun.
     */
    public function test_reordering_the_routine_changes_position_order(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise('Barbell Back Squat');
        $row = $this->exercise('Barbell Row');
        $press = $this->exercise('Overhead Press');

        $rows = ActionExercise::factory()
            ->count(3)
            ->sequence(
                ['exercise_id' => $squat->id],
                ['exercise_id' => $row->id],
                ['exercise_id' => $press->id],
            )
            ->create(['action_id' => $action->id]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.reorder', $action), [
                'order' => [$rows[2]->id, $rows[0]->id, $rows[1]->id],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(
            [$press->id, $squat->id, $row->id],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('exercise_id')->all(),
        );
        $this->assertSame(
            [1, 2, 3],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('position')->all(),
        );
    }

    /**
     * A partial or padded order must be refused rather than partly applied —
     * a silent partial reorder would leave positions the caller cannot
     * predict, and a row missing from `order` would be left pointing nowhere
     * sane.
     */
    public function test_reorder_refuses_a_payload_that_does_not_name_every_current_exercise(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $rows = ActionExercise::factory()->count(2)->create(['action_id' => $action->id]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.reorder', $action), [
                'order' => [$rows[0]->id],
            ])
            ->assertSessionHasErrors('order');

        $this->assertSame(
            [$rows[0]->id, $rows[1]->id],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('id')->all(),
        );
    }

    /**
     * Removing a row must not touch the Exercise it named nor any
     * PerformedSet already recorded against it — history does not vanish
     * because a routine changed. It does renormalize the remaining
     * positions, which is also what keeps `store`'s "next position is a
     * plain count" arithmetic correct after a removal.
     *
     * Killing mutation: swap `$actionExercise->delete()` for a delete of the
     * underlying Exercise (or scope PerformedSet's cleanup to the removed
     * exercise). Either turns the last two assertions red — verified by
     * direct mutation and rerun.
     */
    public function test_removing_an_exercise_keeps_the_exercise_and_its_performed_sets(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $squat = $this->exercise('Barbell Back Squat');
        $swing = $this->exercise('Kettlebell Swing');

        $rows = ActionExercise::factory()
            ->count(2)
            ->sequence(
                ['exercise_id' => $squat->id],
                ['exercise_id' => $swing->id],
            )
            ->create(['action_id' => $action->id]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);
        PerformedSet::factory()->count(3)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
        ]);

        $this->actingAs($user)
            ->delete(route('actions.exercises.destroy', [$action, $rows[0]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // The routine row for squat is gone, and the survivor closed the gap.
        $this->assertSame(
            [$swing->id],
            ActionExercise::where('action_id', $action->id)->pluck('exercise_id')->all(),
        );
        $this->assertSame(1, ActionExercise::where('action_id', $action->id)->value('position'));

        // The Exercise and the sets already recorded against it are untouched.
        $this->assertTrue(Exercise::whereKey($squat->id)->exists());
        $this->assertSame(3, PerformedSet::where('exercise_id', $squat->id)->count());
    }

    /**
     * Killing mutation: replace `Exercise::availableTo($this->user())` with a
     * bare `exists:exercises,id` rule. The stranger's private row still
     * exists, so the request would succeed and this test would fail —
     * verified by direct mutation and rerun.
     */
    public function test_an_exercise_outside_the_users_catalogue_is_refused(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $stranger = $this->user();
        $private = Exercise::factory()->for($stranger)->create(['name' => "Stranger's own move"]);

        $this->actingAs($user)
            ->post(route('actions.exercises.store', $action), [
                'exercise_id' => $private->id,
                'target_sets' => 3,
                'target_reps' => 8,
            ])
            ->assertSessionHasErrors('exercise_id');

        $this->assertSame(0, ActionExercise::count());
    }

    /**
     * The positive control for the test above: `availableTo` has two
     * branches (shared, and mine), and rejecting a stranger's row alone
     * cannot tell a scope missing the "mine" branch from a correct one.
     */
    public function test_a_users_own_private_exercise_can_be_added(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $mine = Exercise::factory()->for($user)->create(['name' => 'My own accessory move']);

        $this->actingAs($user)
            ->post(route('actions.exercises.store', $action), [
                'exercise_id' => $mine->id,
                'target_sets' => 3,
                'target_reps' => 8,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ActionExercise::where('exercise_id', $mine->id)->count());
    }

    public function test_another_users_action_is_refused_when_adding_an_exercise(): void
    {
        $owner = $this->user();
        $action = $this->gymAction($owner);
        $intruder = $this->user();
        $exercise = $this->exercise();

        $this->actingAs($intruder)
            ->post(route('actions.exercises.store', $action), [
                'exercise_id' => $exercise->id,
                'target_sets' => 3,
                'target_reps' => 8,
            ])
            ->assertForbidden();

        $this->assertSame(0, ActionExercise::count());
    }

    public function test_another_users_action_is_refused_when_reordering(): void
    {
        $owner = $this->user();
        $action = $this->gymAction($owner);
        $intruder = $this->user();

        $rows = ActionExercise::factory()->count(2)->create(['action_id' => $action->id]);

        $this->actingAs($intruder)
            ->patch(route('actions.exercises.reorder', $action), [
                'order' => [$rows[1]->id, $rows[0]->id],
            ])
            ->assertForbidden();

        $this->assertSame(
            [$rows[0]->id, $rows[1]->id],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('id')->all(),
        );
    }

    public function test_another_users_action_is_refused_when_removing_an_exercise(): void
    {
        $owner = $this->user();
        $action = $this->gymAction($owner);
        $intruder = $this->user();

        $row = ActionExercise::factory()->create(['action_id' => $action->id]);

        $this->actingAs($intruder)
            ->delete(route('actions.exercises.destroy', [$action, $row]))
            ->assertForbidden();

        $this->assertSame(1, ActionExercise::where('id', $row->id)->count());
    }

    /**
     * The URL names both an action and a row, and Gate::authorize only
     * checks the action. Without a check that the row actually belongs to
     * that action, a user could own action A and use its id in the URL to
     * delete a row that actually belongs to action B — theirs or not.
     *
     * Killing mutation: delete the `abort_unless` line. The row named in the
     * URL, from a second action the same user owns, would be deleted despite
     * naming the wrong action — verified by direct mutation and rerun.
     */
    public function test_removing_a_row_that_belongs_to_a_different_action_is_refused(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $otherAction = $this->gymAction($user);

        $row = ActionExercise::factory()->create(['action_id' => $otherAction->id]);

        $this->actingAs($user)
            ->delete(route('actions.exercises.destroy', [$action, $row]))
            ->assertNotFound();

        $this->assertSame(1, ActionExercise::where('id', $row->id)->count());
    }

    /**
     * `target_reps` is a single integer, not a range: v1 says "10", not
     * "8-12" — see ActionExercise's own doc block for why.
     *
     * Killing mutation: drop the `integer` rule from `target_reps`, leaving
     * only `required`. "8-12" then passes validation — verified by direct
     * mutation and rerun.
     */
    public function test_target_reps_rejects_a_range_string(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);
        $exercise = $this->exercise();

        $this->actingAs($user)
            ->post(route('actions.exercises.store', $action), [
                'exercise_id' => $exercise->id,
                'target_sets' => 3,
                'target_reps' => '8-12',
            ])
            ->assertSessionHasErrors('target_reps');

        $this->assertSame(0, ActionExercise::count());
    }
}
