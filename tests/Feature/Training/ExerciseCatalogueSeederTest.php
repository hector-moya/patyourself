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
     * This does NOT fail if the wrapping closure in `availableTo` is removed
     * while keeping the brief's literal clause order — confirmed by
     * inspecting the compiled SQL, which is identical either way.
     * Eloquent's `Builder::callScope()` nests whatever new where clauses a
     * scope contributes, but which boolean it ANDs that nest with is taken
     * from the *first* new clause the scope added (see
     * `groupWhereSliceForScope()` in the framework); `whereNull` runs first
     * here, so that boolean already comes out 'and'. That safety is
     * order-dependent, not structural: drop the closure and merely swap the
     * call order to `orWhere(...)->orWhereNull(...)` — no clause removed,
     * nothing else changed — and the leak reappears (confirmed:
     * `... where "id" = ? or ("user_id" = ? or "user_id" is null)`). The
     * closure is what makes the AND unconditional instead of depending on
     * which clause happens to run first, which is why it stays even though
     * it doesn't change today's SQL for today's clause order.
     *
     * Given the closure is in place, this test's actual killing mutation is
     * the same one that kills the shared-row assertion above: removing
     * `whereNull('user_id')` from inside the closure. With that removed,
     * `$found` below turns true, because `$viewer`'s own exercise then
     * satisfies `user_id = $viewer->id` regardless of the outer `whereKey`.
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
