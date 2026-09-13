<?php

namespace Tests\Feature\Training;

use App\Actions\Training\UpdateRoutineExercise;
use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editing a routine row's targets — the gap {@see UpdateRoutineExercise}
 * closes. Before it existed, changing a target meant removing the row and
 * adding it back, which drops it to the end of the routine.
 */
class RoutineTargetEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Editing exists because remove-and-re-add is the only way to change a
     * target today, and it drops the row to the end of the routine. Position
     * surviving is the point, so it is asserted, not assumed.
     *
     * Killing mutation: implement the writer as a delete plus a create. The
     * position assertion fails.
     */
    public function test_editing_a_target_keeps_the_row_in_its_place(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();

        $first = ActionExercise::factory()->for($action)->create([
            'position' => 1, 'target_sets' => 3, 'target_reps' => 10,
        ]);
        $second = ActionExercise::factory()->for($action)->create([
            'position' => 2, 'target_sets' => 3, 'target_reps' => 10,
        ]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $first]), [
                'target_sets' => 4,
                'target_reps' => 8,
            ])
            ->assertRedirect();

        $first->refresh();

        $this->assertSame(4, $first->target_sets);
        $this->assertSame(8, $first->target_reps);
        $this->assertSame(1, $first->position);
        $this->assertSame(2, $second->fresh()->position);
    }

    /**
     * Targets are the prescription; performed sets are what happened. Changing
     * one must never rewrite the other.
     */
    public function test_editing_a_target_leaves_recorded_sets_alone(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $exercise = Exercise::factory()->create();

        $row = ActionExercise::factory()->for($action)->create([
            'exercise_id' => $exercise->id,
            'position' => 1, 'target_sets' => 3, 'target_reps' => 10,
        ]);

        $occurrence = Occurrence::factory()->for($action)->create();
        $set = PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
            'set_number' => 1,
            'reps' => 12,
        ]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 5,
                'target_reps' => 5,
            ])
            ->assertRedirect();

        $this->assertSame(12, $set->fresh()->reps);
    }

    /** A routine belongs to its loop's owner, like every other write here. */
    public function test_a_stranger_cannot_edit_a_target(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for($owner)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $row = ActionExercise::factory()->for($action)->create(['position' => 1]);

        $this->actingAs($stranger)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 4, 'target_reps' => 8,
            ])
            ->assertForbidden();
    }

    /**
     * The row must belong to the action in the URL. Without this check, owning
     * one action would be enough to edit another's routine.
     */
    public function test_a_row_from_another_action_is_unreachable(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $other = Action::factory()->for($loop)->for($strategy)->create();
        $row = ActionExercise::factory()->for($other)->create(['position' => 1]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 4, 'target_reps' => 8,
            ])
            ->assertNotFound();
    }

    /** Zero sets is not a routine row, it is a removal. */
    public function test_targets_below_one_are_refused(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $row = ActionExercise::factory()->for($action)->create(['position' => 1]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 0, 'target_reps' => 10,
            ])
            ->assertSessionHasErrors('target_sets');
    }
}
