<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\Exercise;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * `training.progression.show` — the progression screen: what was lifted on
 * one exercise, for how many reps, on what date, newest first.
 *
 * Keyed on the exercise alone rather than on an occasion, so most of these
 * tests never need one — the catalogue-ownership check
 * ({@see ExerciseScreenStubTest}'s own `test_another_users_private_exercise_is_not_found`
 * is the sibling this file's IDOR guard is modelled on) is settled straight
 * off the bound model.
 */
class ProgressionScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-09-07 19:00:00');
    }

    private function user(string $timezone = 'UTC'): User
    {
        return User::factory()->create(['timezone' => $timezone]);
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

    /**
     * Killing mutation: reverse the read model's sort. The two sessions would
     * then render oldest first, and `sessions.0.occurrence_id` /
     * `sessions.1.occurrence_id` would be swapped — red on order. Verified by
     * direct mutation and rerun.
     */
    public function test_it_renders_the_exercises_history_newest_first(): void
    {
        $user = $this->user();
        // Clock-scheduled, not anchored: the read this screen depends on has
        // to work off the same occasion grid a real recurring routine
        // produces, not only the anchored shortcut every batch-1 gym test
        // used.
        $action = $this->clockScheduledGymAction($user);
        $squat = Exercise::factory()->create(['name' => 'Barbell Back Squat']);

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

        $this->actingAs($user)
            ->get("/exercises/{$squat->id}/progression")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/progression')
                ->where('exercise.id', $squat->id)
                ->where('exercise.name', 'Barbell Back Squat')
                ->has('sessions', 2)
                ->where('sessions.0.occurrence_id', $newer->id)
                ->where('sessions.0.sets.0.reps', 8)
                ->where('sessions.1.occurrence_id', $older->id)
                ->where('sessions.1.sets.0.reps', 5)
            );
    }

    /**
     * Killing mutation: drop the `auth` middleware group from the route. The
     * request would then reach the controller and render 200 instead of
     * redirecting — red. Verified by direct mutation and rerun.
     */
    public function test_a_guest_is_redirected_to_login(): void
    {
        $exercise = Exercise::factory()->create();

        $this->get("/exercises/{$exercise->id}/progression")->assertRedirect('/login');
    }

    /**
     * The catalogue is shared, so owning an account says nothing about
     * owning an exercise. Refused as a 404, not a 403: a 403 would confirm
     * the id belongs to someone real.
     *
     * Killing mutation: delete the `abort_unless(... availableTo ...)` guard.
     * The stranger's row is still bound off the id in the URL and the screen
     * renders 200 with their name on it — red. Verified by direct mutation
     * and rerun.
     */
    public function test_another_users_private_exercise_is_a_404(): void
    {
        $user = $this->user();
        $stranger = $this->user();
        $private = Exercise::factory()->create([
            'user_id' => $stranger->id,
            'name' => 'Stranger Only Lift',
        ]);

        $response = $this->actingAs($user)->get("/exercises/{$private->id}/progression");

        $response->assertNotFound();
        $response->assertDontSee('Stranger Only Lift');
    }

    /**
     * The other half of the same rule: the shared catalogue (no owner at
     * all) has to stay reachable, not only a user's own additions.
     *
     * Killing mutation: narrow the guard from `availableTo($request->user())`
     * to `where('user_id', $user->id)`. A shared row's `user_id` is `null`,
     * which never equals the user's id, so this exercise would 404 — red.
     * Verified by direct mutation and rerun.
     */
    public function test_a_shared_catalogue_exercise_is_reachable(): void
    {
        $user = $this->user();
        $exercise = Exercise::factory()->create(['name' => 'Barbell Bench Press', 'user_id' => null]);

        $this->actingAs($user)
            ->get("/exercises/{$exercise->id}/progression")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/progression')
                ->where('exercise.name', 'Barbell Bench Press')
            );
    }

    /**
     * An exercise nobody has ever recorded against still has a progression
     * screen — an empty history is not an error.
     *
     * Killing mutation: return early with a 404 when `sessions` is empty.
     * This test's `assertOk()` would then see a 404 — red. Verified by direct
     * mutation and rerun.
     */
    public function test_it_renders_with_no_history_at_all(): void
    {
        $user = $this->user();
        $exercise = Exercise::factory()->create();

        $this->actingAs($user)
            ->get("/exercises/{$exercise->id}/progression")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('training/progression')
                ->where('sessions', [])
            );
    }

    /**
     * `performed_at` is `occurrences.scheduled_for` in UTC; converting it to
     * the viewer's own timezone is the controller's job, not the read
     * model's. `Pacific/Auckland` is a non-zero, non-round offset, so a test
     * that merely checked a string was present would prove nothing.
     *
     * Killing mutation: drop `->timezone($timezone)` from the mapping. The
     * emitted string would then carry `+00:00` instead of `+12:00` and this
     * assertion on the offset fails — red. Verified by direct mutation and
     * rerun.
     */
    public function test_the_dates_are_in_the_users_own_timezone(): void
    {
        $user = $this->user('Pacific/Auckland');
        $action = $this->anchoredGymAction($user);
        $exercise = Exercise::factory()->create();

        // 2026-09-07 19:00 UTC is already the morning of the 8th in Auckland.
        $occurrence = Occurrence::factory()->for($action)->create(['scheduled_for' => now()]);
        PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
            'set_number' => 1,
            'reps' => 5,
            'weight' => 40,
        ]);

        $this->actingAs($user)
            ->get("/exercises/{$exercise->id}/progression")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sessions.0.performed_at', '2026-09-08T07:00:00+12:00')
            );
    }
}
