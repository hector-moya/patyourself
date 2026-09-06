<?php

namespace Tests\Feature\Training;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Table-shape coverage for the exercise catalogue. The seeder that actually
 * imports `database/data/exercises.json` is Task 2 — this only pins the
 * schema and the `availableTo` scope that the seeded rows will be queried
 * through.
 */
class ExerciseCatalogueSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pins the nullability of `exercises.user_id`, not the factory's default.
     * The factory already defaults `user_id` to null, so on its own this only
     * asserts the fixture. It earns its place as a schema assertion: making
     * `user_id` NOT NULL in the migration makes `Exercise::factory()->create()`
     * throw, which is the mutation that turns this red.
     */
    public function test_a_catalogue_exercise_belongs_to_nobody(): void
    {
        $exercise = Exercise::factory()->create();

        $this->assertNull($exercise->user_id);
        $this->assertDatabaseHas('exercises', ['id' => $exercise->id, 'user_id' => null]);
    }

    /**
     * Killing mutations: removing `whereNull('user_id')` from the scope makes
     * this fail on `$shared`; removing the `orWhere` makes it fail on `$mine`.
     */
    public function test_a_user_added_exercise_is_scoped_to_them(): void
    {
        $mine = Exercise::factory()->for(User::factory())->create();
        $theirs = Exercise::factory()->for(User::factory())->create();
        $shared = Exercise::factory()->create();

        $visible = Exercise::query()->availableTo($mine->user)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertTrue($visible->contains($shared->id));
        $this->assertFalse($visible->contains($theirs->id));
    }

    /**
     * A realistic authorization-shaped composition: filter to one specific
     * row, then ask whether it is available to this viewer. `$someoneElses`
     * must not leak in just because `$viewer` owns an unrelated exercise.
     *
     * Killing mutation, verified by direct mutation + rerun: changing the
     * scope's outer `where(function...)` to `orWhere(function...)` flips
     * `$found` to `true` without touching test 1 or test 2 — the asymmetry
     * that makes this test non-redundant with the one above.
     *
     * NOT a killing mutation, also verified by direct mutation + rerun:
     * removing `whereNull('user_id')` from inside the closure. That fails
     * `test_a_user_added_exercise_is_scoped_to_them` on `$shared` but leaves
     * this test green, because `$found` is checking a row that already
     * belongs to a different, non-viewer user regardless of the null branch.
     */
    public function test_the_available_to_scope_stays_grouped_when_composed_with_another_where(): void
    {
        $viewer = User::factory()->create();
        Exercise::factory()->for($viewer)->create();

        $someoneElses = Exercise::factory()->for(User::factory())->create();

        $found = Exercise::query()
            ->whereKey($someoneElses->id)
            ->availableTo($viewer)
            ->exists();

        $this->assertFalse($found);
    }
}
