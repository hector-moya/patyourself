<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\User;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The dedicated session screen — `training/session` — and the seam that leads
 * into it.
 *
 * `SessionScreen::for()` already pins the read model's own ordering, N+1
 * safety and eager-loading (see `SessionScreenTest`); this file pins what the
 * *controller* does with it: the props shape the page renders from, the
 * authorization gate, and the fact that beginning to record now lands the user
 * on this screen rather than back where they started.
 */
class SessionScreenRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-09-10 07:00:00');
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * A cue-anchored action on a loop that records through the gym workflow.
     * `anchored()` pins both of ActionFactory's random fields so nothing here
     * depends on which values the factory would otherwise have rolled.
     */
    private function gymAction(User $user, string $title = 'Upper A'): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create(['title' => $title]);
    }

    /**
     * Killing mutation: replace `'performed_count' => $row['performed_sets']->count()`
     * with a hardcoded `0`. Squat (2 recorded) and press (1 recorded) then both
     * read 0, and this test's assertions on those two rows fail — verified by
     * direct mutation and rerun.
     */
    public function test_it_renders_the_routine_in_position_order_with_targets_and_recorded_counts(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);

        $squat = Exercise::factory()->create(['name' => 'Barbell Back Squat']);
        $row = Exercise::factory()->create(['name' => 'Barbell Row']);
        $press = Exercise::factory()->create(['name' => 'Overhead Press']);

        // Positions deliberately out of step with both insertion order and id
        // order. `ActionExerciseFactory::configure()` sequences position to
        // `index + 1`, so leaving it alone would make all three coincide and
        // the ordering assertions below would pass against a controller that
        // never sorted at all.
        ActionExercise::factory()
            ->count(3)
            ->sequence(
                ['exercise_id' => $squat->id, 'target_sets' => 5, 'target_reps' => 5, 'position' => 3],
                ['exercise_id' => $row->id, 'target_sets' => 4, 'target_reps' => 8, 'position' => 1],
                ['exercise_id' => $press->id, 'target_sets' => 3, 'target_reps' => 10, 'position' => 2],
            )
            ->create(['action_id' => $action->id]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        PerformedSet::factory()->count(2)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $squat->id,
        ]);
        PerformedSet::factory()->count(1)->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $press->id,
        ]);

        $this->actingAs($user)
            ->get("/occurrences/{$occurrence->id}/session")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/session')
                ->where('occurrence_id', $occurrence->id)
                ->where('action_id', $action->id)
                ->where('action_title', 'Upper A')
                ->where('scheduled_for', $occurrence->scheduled_for->toIso8601String())
                ->has('exercises', 3)
                // Position order — 1, 2, 3 — which is row, press, squat.
                // Insertion and id order would both be squat, row, press.
                ->where('exercises.0.name', 'Barbell Row')
                ->where('exercises.0.target_sets', 4)
                ->where('exercises.0.target_reps', 8)
                ->where('exercises.0.performed_count', 0)
                ->where('exercises.1.name', 'Overhead Press')
                ->where('exercises.1.performed_count', 1)
                ->where('exercises.2.name', 'Barbell Back Squat')
                ->where('exercises.2.target_sets', 5)
                ->where('exercises.2.target_reps', 5)
                ->where('exercises.2.performed_count', 2)
            );
    }

    /**
     * "An action with no template logs exactly as it does today. The tracker
     * is additive." A plain action has no routine to read — the screen must
     * still render, with nothing to show for the tracker.
     *
     * Killing mutation: skip the `SessionScreen::for()` call and return a
     * hardcoded non-empty exercise regardless of the action's own routine —
     * verified by direct mutation and rerun.
     */
    public function test_an_action_with_no_routine_renders_no_exercises(): void
    {
        $user = $this->user();
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->anchored()
            ->create();

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        $this->actingAs($user)
            ->get("/occurrences/{$occurrence->id}/session")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/session')
                ->where('exercises', [])
            );
    }

    /**
     * Killing mutation: revert `materialise`'s return to `back()->with(...)`.
     * The redirect then lands on the referring page instead of the session
     * screen this occasion just produced, and `assertRedirect` against the
     * named route fails — verified by direct mutation and rerun.
     */
    public function test_materialising_redirects_straight_into_the_session_it_created(): void
    {
        $user = $this->user();
        $action = $this->gymAction($user);

        $response = $this->actingAs($user)->post("/actions/{$action->id}/session");

        $occurrence = Occurrence::sole();

        $response->assertRedirect(route('training.session.show', $occurrence));
    }

    public function test_another_users_occurrence_cannot_be_opened(): void
    {
        $owner = $this->user();
        $occurrence = Occurrence::factory()->create([
            'action_id' => $this->gymAction($owner)->id,
        ]);

        $intruder = $this->user();

        $this->actingAs($intruder)
            ->get("/occurrences/{$occurrence->id}/session")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $occurrence = Occurrence::factory()->create([
            'action_id' => $this->gymAction($this->user())->id,
        ]);

        $this->get("/occurrences/{$occurrence->id}/session")->assertRedirect('/login');
    }
}
