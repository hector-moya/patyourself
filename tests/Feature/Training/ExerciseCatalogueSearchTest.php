<?php

namespace Tests\Feature\Training;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `training.exercises.index` — searching the catalogue, for the routine
 * editor's picker.
 *
 * The catalogue is 876 imported rows plus the user's own additions, which is
 * why the picker needs a search seam at all rather than a prop on the loop
 * screen. Two things this has to get right: what it is allowed to return, and
 * how much.
 */
class ExerciseCatalogueSearchTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * Killing mutation: drop the `where('name', 'like', ...)` filter, so every
     * available row comes back regardless of the term. "Barbell Row" would
     * then appear alongside the two presses and the count assertion fails —
     * verified by direct mutation and rerun.
     */
    public function test_it_returns_the_shared_catalogue_rows_matching_the_term(): void
    {
        $user = $this->user();

        Exercise::factory()->create(['name' => 'Barbell Bench Press']);
        Exercise::factory()->create(['name' => 'Dumbbell Bench Press']);
        Exercise::factory()->create(['name' => 'Barbell Row']);

        $response = $this->actingAs($user)->getJson('/exercises?q=bench');

        $response->assertOk();
        $this->assertSame(
            ['Barbell Bench Press', 'Dumbbell Bench Press'],
            array_column($response->json('exercises'), 'name'),
        );
    }

    /**
     * The picker has to be able to tell two rows with the same name apart, and
     * in this catalogue equipment is what does it.
     *
     * Killing mutation: return only `id` and `name`. `equipment` comes back
     * absent and the assertion below fails — verified by direct mutation and
     * rerun.
     */
    public function test_each_match_carries_enough_to_choose_between_two_of_the_same_name(): void
    {
        $user = $this->user();

        Exercise::factory()->create([
            'name' => 'Bench Press',
            'equipment' => 'barbell',
            'category' => 'strength',
        ]);

        $response = $this->actingAs($user)->getJson('/exercises?q=bench');

        $response->assertOk()->assertJsonPath('exercises.0.equipment', 'barbell');
        $response->assertJsonPath('exercises.0.category', 'strength');
    }

    /**
     * The catalogue is shared, so a search scoped by name alone would hand one
     * user another's private additions.
     *
     * Killing mutation: drop `->availableTo($request->user())`. The stranger's
     * row is returned and this test's name list gains it — verified by direct
     * mutation and rerun.
     */
    public function test_it_never_returns_another_users_private_exercise(): void
    {
        $stranger = $this->user();
        Exercise::factory()->for($stranger)->create(['name' => 'Stranger Only Press']);

        $shared = Exercise::factory()->create(['name' => 'Shared Bench Press']);

        $user = $this->user();

        $response = $this->actingAs($user)->getJson('/exercises?q=press');

        $response->assertOk();
        $this->assertSame(
            [$shared->id],
            array_column($response->json('exercises'), 'id'),
        );
    }

    /**
     * The positive control for the test above: `availableTo` has two branches
     * — shared, and mine — and refusing a stranger's row alone cannot tell a
     * scope missing the "mine" branch from a correct one.
     *
     * Killing mutation: narrow the scope to `whereNull('user_id')`. The
     * shared row still returns and only this test goes red — verified by
     * direct mutation and rerun.
     */
    public function test_it_returns_the_users_own_private_exercise(): void
    {
        $user = $this->user();
        $mine = Exercise::factory()->for($user)->create(['name' => 'My Own Press']);

        $response = $this->actingAs($user)->getJson('/exercises?q=press');

        $response->assertOk();
        $this->assertContains($mine->id, array_column($response->json('exercises'), 'id'));
    }

    /**
     * Several hundred rows match a term as short as "press", and the picker
     * cannot be a scroll of the whole catalogue.
     *
     * Killing mutation: remove `->limit(self::LIMIT)`. All 25 rows come back
     * and the count assertion fails — verified by direct mutation and rerun.
     */
    public function test_it_caps_how_many_matches_come_back(): void
    {
        $user = $this->user();

        for ($i = 1; $i <= 25; $i++) {
            Exercise::factory()->create(['name' => 'Press Variation '.$i]);
        }

        $response = $this->actingAs($user)->getJson('/exercises?q=press');

        $response->assertOk();
        $this->assertCount(20, $response->json('exercises'));
    }

    /**
     * A hand-edited `?q[]=x` hands `query()` an array, and `(string) $array`
     * raises a warning Laravel's handler turns into a 500 — the trap
     * `IntentionController::index` already records for its own `q`.
     *
     * Killing mutation: drop the `is_string` guard and cast the raw value
     * straight to string. The request 500s instead of returning 200 — verified
     * by direct mutation and rerun.
     */
    public function test_an_array_search_term_does_not_error(): void
    {
        $user = $this->user();
        Exercise::factory()->create(['name' => 'Barbell Bench Press']);

        $this->actingAs($user)->getJson('/exercises?q[]=bench')->assertOk();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/exercises')->assertRedirect('/login');
    }
}
