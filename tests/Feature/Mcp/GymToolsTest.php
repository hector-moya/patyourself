<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\AddRoutineExerciseTool;
use App\Mcp\Tools\GetLoopTool;
use App\Mcp\Tools\RemoveRoutineExerciseTool;
use App\Mcp\Tools\SearchExercisesTool;
use App\Mcp\Tools\UpdateLoopTool;
use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Configuring the gym from the connector.
 *
 * The web app could already do all of this; the connector could not do any of
 * it, so a loop created through a conversation arrived with the workflow unset
 * and no way to set it. That is not a hypothetical — it happened, and the
 * symptom was a gym loop showing "recording nothing extra" with no exercises
 * and no explanation.
 *
 * Every write here goes through the same Action classes the controllers use, so
 * position assignment, renumbering and catalogue scoping are not reimplemented
 * at a second boundary.
 */
class GymToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function loop(User $user, ?string $workflow = null): Intention
    {
        return Intention::factory()->for($user)->create([
            'title' => 'Gym three times a week',
            'status' => Intention::STATUS_ACTIVE,
            'workflow' => $workflow,
        ]);
    }

    /**
     * Reuses the loop's strategy rather than making one per action: three gym
     * sessions are three actions on ONE experiment, which is the shape this
     * whole feature exists to support — and `strategies` is unique on
     * (intention_id, version), so a strategy per action would not even insert.
     */
    private function action(Intention $loop, string $title = 'Upper body'): Action
    {
        $strategy = Strategy::query()->where('intention_id', $loop->id)->first()
            ?? Strategy::factory()->for($loop)->create();

        return Action::factory()->for($loop)->for($strategy)->create(['title' => $title]);
    }

    /* ---------------------------------------------------------------- */
    /* Turning the workflow on */
    /* ---------------------------------------------------------------- */

    public function test_it_switches_the_gym_workflow_on(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        $response = PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'workflow' => 'gym',
        ]);

        $response->assertOk();

        $this->assertSame('gym', $this->payload($response)['workflow']);
        $this->assertSame('gym', $loop->fresh()->workflow);
    }

    /**
     * A workflow is spelled by `config/workflows.php`, never typed by a user.
     * The free-text tag this replaced failed silently on "Gym" or a trailing
     * space, with nothing on screen to say why — so an unknown name has to be
     * a refusal rather than a write.
     *
     * Kills: dropping the registry rule and letting any string through.
     */
    public function test_it_refuses_a_workflow_the_registry_does_not_know(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);

        $response = PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'workflow' => 'Gym',
        ]);

        $response->assertHasErrors();
        $this->assertNull($loop->fresh()->workflow);
    }

    /**
     * Recording nothing extra is what almost every loop does, so getting back
     * to it must be possible. An empty string is the same "nothing extra" the
     * web picker's first option submits, and it has to land as null rather
     * than as an empty string the registry would then fail to recognise.
     *
     * Kills: storing '' verbatim, which reads as a workflow named "".
     */
    public function test_an_empty_workflow_clears_it_back_to_nothing_extra(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, 'gym');

        $response = PatYourSelfServer::actingAs($user)->tool(UpdateLoopTool::class, [
            'intention_id' => $loop->id,
            'workflow' => '',
        ]);

        $response->assertOk();
        $this->assertNull($loop->fresh()->workflow);
    }

    /* ---------------------------------------------------------------- */
    /* Seeing what is there */
    /* ---------------------------------------------------------------- */

    /**
     * Without this there is no way to reach an action from a conversation: the
     * id is needed to attach anything, and a freshly created action's id was
     * the only one obtainable. Kills: leaving actions off the payload.
     */
    public function test_get_loop_lists_the_actions_with_their_ids(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, 'gym');
        $monday = $this->action($loop, 'Upper body Monday');
        $thursday = $this->action($loop, 'Lower body Thursday');

        $response = PatYourSelfServer::actingAs($user)->tool(GetLoopTool::class, [
            'intention_id' => $loop->id,
        ]);

        $response->assertOk();

        $actions = $this->payload($response)['actions'];

        $this->assertSame(
            [$monday->id, $thursday->id],
            array_column($actions, 'action_id'),
        );
        $this->assertSame(
            ['Upper body Monday', 'Lower body Thursday'],
            array_column($actions, 'title'),
        );
    }

    public function test_get_loop_shows_each_action_its_routine_in_order(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, 'gym');
        $action = $this->action($loop);

        $bench = Exercise::factory()->create(['name' => 'Barbell Bench Press', 'user_id' => null]);
        $row = Exercise::factory()->create(['name' => 'Bent Over Barbell Row', 'user_id' => null]);

        ActionExercise::factory()->create([
            'action_id' => $action->id,
            'exercise_id' => $row->id,
            'position' => 2,
            'target_sets' => 3,
            'target_reps' => 10,
        ]);
        ActionExercise::factory()->create([
            'action_id' => $action->id,
            'exercise_id' => $bench->id,
            'position' => 1,
            'target_sets' => 4,
            'target_reps' => 8,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(GetLoopTool::class, [
            'intention_id' => $loop->id,
        ]);

        $routine = $this->payload($response)['actions'][0]['routine'];

        $this->assertSame(['Barbell Bench Press', 'Bent Over Barbell Row'], array_column($routine, 'exercise'));
        $this->assertSame([4, 3], array_column($routine, 'target_sets'));
        $this->assertSame([8, 10], array_column($routine, 'target_reps'));
    }

    /**
     * Null and [] mean different things — "this loop has no configuration
     * surface" against "this action's routine is empty" — and the web payload
     * already sends them apart for exactly that reason.
     *
     * Kills: returning [] for a plain loop, which would invite a conversation
     * about adding exercises to a loop that records nothing extra.
     */
    public function test_get_loop_sends_no_routine_at_all_for_a_plain_loop(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user);
        $this->action($loop);

        $response = PatYourSelfServer::actingAs($user)->tool(GetLoopTool::class, [
            'intention_id' => $loop->id,
        ]);

        $payload = $this->payload($response);

        $this->assertNull($payload['workflow']);
        $this->assertNull($payload['actions'][0]['routine']);
    }

    /* ---------------------------------------------------------------- */
    /* Finding an exercise */
    /* ---------------------------------------------------------------- */

    public function test_it_searches_the_catalogue_by_partial_name(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        Exercise::factory()->create(['name' => 'Barbell Bench Press', 'user_id' => null]);
        Exercise::factory()->create(['name' => 'Incline Dumbbell Press', 'user_id' => null]);
        Exercise::factory()->create(['name' => 'Barbell Squat', 'user_id' => null]);

        $response = PatYourSelfServer::actingAs($user)->tool(SearchExercisesTool::class, [
            'query' => 'bench',
        ]);

        $response->assertOk();

        $names = array_column($this->payload($response)['exercises'], 'name');

        $this->assertSame(['Barbell Bench Press'], $names);
    }

    /**
     * The same scope the controllers apply: the shared catalogue plus the
     * user's own additions, and nobody else's.
     *
     * Kills: querying Exercise without `availableTo`, which would expose one
     * user's private additions to every other user's connector.
     */
    public function test_it_never_returns_another_users_own_exercise(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);

        Exercise::factory()->create(['name' => 'Shared Bench Press', 'user_id' => null]);
        Exercise::factory()->create(['name' => 'My Own Bench Press', 'user_id' => $user->id]);
        Exercise::factory()->create(['name' => 'Secret Bench Press', 'user_id' => $stranger->id]);

        $response = PatYourSelfServer::actingAs($user)->tool(SearchExercisesTool::class, [
            'query' => 'bench press',
        ]);

        $names = array_column($this->payload($response)['exercises'], 'name');

        $this->assertContains('Shared Bench Press', $names);
        $this->assertContains('My Own Bench Press', $names);
        $this->assertNotContains('Secret Bench Press', $names);
    }

    /**
     * An empty catalogue is the state production was actually in, and the
     * reason every search "found nothing" with nothing to say why. The tool
     * says so rather than returning a bare empty list.
     */
    public function test_it_says_the_catalogue_is_empty_rather_than_finding_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $response = PatYourSelfServer::actingAs($user)->tool(SearchExercisesTool::class, [
            'query' => 'bench press',
        ]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame([], $payload['exercises']);
        $this->assertTrue($payload['catalogue_empty']);
    }

    /* ---------------------------------------------------------------- */
    /* Building a routine */
    /* ---------------------------------------------------------------- */

    public function test_it_appends_exercises_to_a_routine_in_order(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, 'gym');
        $action = $this->action($loop);

        $squat = Exercise::factory()->create(['name' => 'Barbell Squat', 'user_id' => null]);
        $deadlift = Exercise::factory()->create(['name' => 'Romanian Deadlift', 'user_id' => null]);

        PatYourSelfServer::actingAs($user)->tool(AddRoutineExerciseTool::class, [
            'action_id' => $action->id,
            'exercise_id' => $squat->id,
            'target_sets' => 4,
            'target_reps' => 8,
        ])->assertOk();

        $second = PatYourSelfServer::actingAs($user)->tool(AddRoutineExerciseTool::class, [
            'action_id' => $action->id,
            'exercise_id' => $deadlift->id,
            'target_sets' => 3,
            'target_reps' => 10,
        ]);

        $second->assertOk();

        $this->assertSame(2, $this->payload($second)['position']);
        $this->assertSame(
            [1, 2],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('position')->all(),
        );
    }

    /**
     * Kills: skipping the catalogue check, which would write a routine row
     * pointing at another user's exercise or at nothing at all.
     */
    public function test_it_refuses_an_exercise_outside_the_users_catalogue(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, 'gym');
        $action = $this->action($loop);

        $theirs = Exercise::factory()->create(['name' => 'Secret Lift', 'user_id' => $stranger->id]);

        $response = PatYourSelfServer::actingAs($user)->tool(AddRoutineExerciseTool::class, [
            'action_id' => $action->id,
            'exercise_id' => $theirs->id,
            'target_sets' => 3,
            'target_reps' => 10,
        ]);

        $response->assertHasErrors();
        $this->assertSame(0, ActionExercise::where('action_id', $action->id)->count());
    }

    /**
     * Kills: resolving the action off the global table instead of through the
     * user's own loops, which would let one connector write into another
     * person's routine.
     */
    public function test_it_refuses_an_action_belonging_to_someone_else(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);
        $theirLoop = $this->loop($stranger, 'gym');
        $theirAction = $this->action($theirLoop);

        $exercise = Exercise::factory()->create(['name' => 'Barbell Squat', 'user_id' => null]);

        $response = PatYourSelfServer::actingAs($user)->tool(AddRoutineExerciseTool::class, [
            'action_id' => $theirAction->id,
            'exercise_id' => $exercise->id,
            'target_sets' => 3,
            'target_reps' => 10,
        ]);

        $response->assertHasErrors();
        $this->assertSame(0, ActionExercise::where('action_id', $theirAction->id)->count());
    }

    public function test_it_removes_a_routine_row_and_closes_the_gap(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loop($user, 'gym');
        $action = $this->action($loop);

        $rows = collect(['A', 'B', 'C'])->map(fn (string $name): ActionExercise => ActionExercise::factory()->create([
            'action_id' => $action->id,
            'exercise_id' => Exercise::factory()->create(['name' => $name, 'user_id' => null])->id,
            'position' => ['A' => 1, 'B' => 2, 'C' => 3][$name],
        ]));

        $response = PatYourSelfServer::actingAs($user)->tool(RemoveRoutineExerciseTool::class, [
            'action_id' => $action->id,
            'action_exercise_id' => $rows[1]->id,
        ]);

        $response->assertOk();

        $this->assertSame(
            [1, 2],
            ActionExercise::where('action_id', $action->id)->orderBy('position')->pluck('position')->all(),
        );
        $this->assertSame(
            ['A', 'C'],
            ActionExercise::where('action_id', $action->id)
                ->orderBy('position')
                ->with('exercise')
                ->get()
                ->map(fn (ActionExercise $row): string => $row->exercise->name)
                ->all(),
        );
    }

    public function test_it_refuses_to_remove_a_row_from_someone_elses_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);
        $theirAction = $this->action($this->loop($stranger, 'gym'));

        $row = ActionExercise::factory()->create([
            'action_id' => $theirAction->id,
            'exercise_id' => Exercise::factory()->create(['user_id' => null])->id,
            'position' => 1,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(RemoveRoutineExerciseTool::class, [
            'action_id' => $theirAction->id,
            'action_exercise_id' => $row->id,
        ]);

        $response->assertHasErrors();
        $this->assertSame(1, ActionExercise::where('action_id', $theirAction->id)->count());
    }
}
