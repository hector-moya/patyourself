<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * What the loop screen hands the two setup surfaces: the workflow registry the
 * picker draws from, and each action's routine for the editor.
 *
 * Both are additive. A loop with no workflow gets `routine: null` on every
 * action and pays for no extra query — `PlainLoopIsUnchangedTest` is the
 * standing guard on that claim; this file pins the props themselves.
 */
class RoutineEditorPropsTest extends TestCase
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

    /**
     * The picker's options come from the server-side registry, not from a
     * hardcoded list in the client — the server is what decides which names
     * `UpdateIntentionRequest` will accept.
     *
     * Killing mutation: hardcode the prop to `[]`. The picker would render
     * with nothing to choose and the assertion below fails — verified by
     * direct mutation and rerun.
     */
    public function test_the_loop_screen_carries_the_workflow_registry(): void
    {
        $user = $this->user();
        $loop = Intention::factory()->for($user)->withWorkflow(null)->create();

        $this->actingAs($user)
            ->get("/loops/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/show')
                ->has('workflows', 1)
                ->where('workflows.0.name', 'gym')
                ->where('workflows.0.label', 'Gym')
            );
    }

    /**
     * Killing mutation: send the routine unconditionally rather than only for
     * a loop whose workflow configures actions. `routine` would come back as
     * `[]` instead of null, and the client could no longer tell "this loop
     * does not configure actions" from "this action's routine is empty" —
     * verified by direct mutation and rerun.
     */
    public function test_a_plain_loops_actions_carry_a_null_routine(): void
    {
        $user = $this->user();
        $loop = Intention::factory()->for($user)->withWorkflow(null)->create();
        Action::factory()->for($loop)->anchored()->create();

        $this->actingAs($user)
            ->get("/loops/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/show')
                ->has('actions', 1)
                ->where('actions.0.routine', null)
            );
    }

    /**
     * Killing mutation: order the routine by `id` instead of `position`. The
     * rows are deliberately inserted with positions out of step with both
     * insertion and id order, so squat-first would come back instead of
     * row-first — verified by direct mutation and rerun.
     */
    public function test_a_gym_loops_actions_carry_their_routine_in_position_order(): void
    {
        $user = $this->user();
        $loop = Intention::factory()->for($user)->withWorkflow('gym')->create();
        $action = Action::factory()->for($loop)->anchored()->create();

        $squat = Exercise::factory()->create(['name' => 'Barbell Back Squat']);
        $row = Exercise::factory()->create(['name' => 'Barbell Row']);

        ActionExercise::factory()
            ->count(2)
            ->sequence(
                ['exercise_id' => $squat->id, 'target_sets' => 5, 'target_reps' => 5, 'position' => 2],
                ['exercise_id' => $row->id, 'target_sets' => 4, 'target_reps' => 8, 'position' => 1],
            )
            ->create(['action_id' => $action->id]);

        $this->actingAs($user)
            ->get("/loops/{$loop->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('loops/show')
                ->has('actions.0.routine', 2)
                ->where('actions.0.routine.0.exercise_name', 'Barbell Row')
                ->where('actions.0.routine.0.target_sets', 4)
                ->where('actions.0.routine.0.target_reps', 8)
                ->where('actions.0.routine.1.exercise_name', 'Barbell Back Squat')
            );
    }

    /**
     * A longer routine must not cost more queries than a short one — trap 5,
     * and the action layer is rendered on every visit to the loop screen.
     *
     * The routine's *length* is what varies, with the action count held at
     * four in both arms. That is deliberate: `Action::nextOccurrenceAt()`
     * already costs one query per action on this screen, which predates this
     * change and is out of scope for it, and varying the action count would
     * measure that instead of the eager load this test is actually about.
     *
     * Killing mutation: drop `->with('actionExercises.exercise')`. The routine
     * rows lazy-load once per action — constant across both arms — but their
     * exercises lazy-load once per *row*, so four rows and twelve rows
     * diverge by eight queries. Verified by direct mutation and rerun.
     */
    public function test_the_action_layer_costs_the_same_however_long_the_routines_are(): void
    {
        $this->assertSame(
            $this->queriesRenderingGymLoopWithRoutinesOf(1),
            $this->queriesRenderingGymLoopWithRoutinesOf(3),
            'The action layer costs more per extra routine row — a relation is being lazy-loaded per row.'
        );
    }

    /** Queries issued rendering a gym loop of four actions, each with a `$rows`-row routine. */
    private function queriesRenderingGymLoopWithRoutinesOf(int $rows): int
    {
        $user = $this->user();
        $loop = Intention::factory()->for($user)->withWorkflow('gym')->create();

        for ($i = 0; $i < 4; $i++) {
            $action = Action::factory()->for($loop)->anchored()->create();

            ActionExercise::factory()->count($rows)->create(['action_id' => $action->id]);
        }

        // The query log rather than DB::listen: a listener registered per call
        // would still be attached on the next one and double-count it.
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($user)->get("/loops/{$loop->id}")->assertOk();

        $count = count(DB::getQueryLog());

        DB::flushQueryLog();
        DB::disableQueryLog();

        return $count;
    }
}
