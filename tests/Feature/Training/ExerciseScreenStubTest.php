<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `training.exercise.show`, the minimal stub `ExerciseController` is.
 *
 * Task 7 owns the real exercise screen (the read model, `LastPerformance`,
 * the set-recording form). This route and controller exist now only so
 * `session.tsx` can link to it through a real, generated Wayfinder helper
 * instead of a hardcoded URL — the route needs a resolvable controller
 * method to generate one from. This file pins just that: the route
 * resolves, is gated the same way every other record-side seam in this
 * module is, and names the component task 7 will fill in.
 *
 * `->component('training/exercise', false)` — the second argument tells the
 * Inertia testing helper not to check the page component file exists on
 * disk. It doesn't yet; that's task 7's to add.
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

    private function occurrenceFor(User $user): Occurrence
    {
        $action = Action::factory()
            ->for(Intention::factory()->for($user)->withWorkflow('gym'))
            ->anchored()
            ->create();

        return Occurrence::factory()->create(['action_id' => $action->id]);
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
                ->component('training/exercise', false)
                ->where('occurrence_id', $occurrence->id)
                ->where('exercise_id', $exercise->id)
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
