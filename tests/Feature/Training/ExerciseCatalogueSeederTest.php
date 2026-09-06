<?php

namespace Tests\Feature\Training;

use App\Models\Exercise;
use App\Models\User;
use Database\Seeders\ExerciseCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JsonException;
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

    public function test_the_seeder_imports_the_whole_catalogue(): void
    {
        $this->seed(ExerciseCatalogueSeeder::class);

        $this->assertSame(876, Exercise::query()->whereNull('user_id')->count());
    }

    /**
     * Re-running must update, not duplicate — a deploy runs seeders more than
     * once over a project's life, and `external_id` is what makes that safe.
     */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(ExerciseCatalogueSeeder::class);
        $this->seed(ExerciseCatalogueSeeder::class);

        $this->assertSame(876, Exercise::query()->count());
    }

    public function test_the_seeder_keeps_a_users_own_additions(): void
    {
        $mine = Exercise::factory()->for(User::factory())->create(['name' => 'The machine my gym has']);

        $this->seed(ExerciseCatalogueSeeder::class);

        $this->assertDatabaseHas('exercises', ['id' => $mine->id, 'name' => 'The machine my gym has']);
    }

    /**
     * These counts (30, 87) are re-counted directly against the committed
     * `database/data/exercises.json`, not derived from this test. If the
     * catalogue file is ever re-fetched from upstream, these numbers can
     * legitimately move — a failure here most likely means the source file
     * changed, not that the seeder itself is broken.
     */
    public function test_nullable_source_fields_survive_the_import(): void
    {
        $this->seed(ExerciseCatalogueSeeder::class);

        // 30 of 876 records have a null `force`, 87 a null `mechanic`. Asserted
        // as counts because a single row could pass by accident.
        $this->assertSame(
            30,
            Exercise::query()->whereNull('force')->count(),
            'Expected 30 rows with a null `force`, re-counted against the committed exercises.json. '
                .'If this file was re-fetched from upstream, that count may have legitimately changed — '
                .'re-verify against database/data/exercises.json before assuming the seeder is broken.'
        );
        $this->assertSame(
            87,
            Exercise::query()->whereNull('mechanic')->count(),
            'Expected 87 rows with a null `mechanic`, re-counted against the committed exercises.json. '
                .'If this file was re-fetched from upstream, that count may have legitimately changed — '
                .'re-verify against database/data/exercises.json before assuming the seeder is broken.'
        );
    }

    /**
     * A truncated or half-written catalogue file must stop the deploy, not seed
     * an empty table and report success.
     *
     * Killing mutation: drop `JSON_THROW_ON_ERROR` from the `File::json()` call.
     * `json_decode` then returns null, `collect(null)` is an empty collection,
     * the import completes silently, and no exception is thrown.
     */
    public function test_a_malformed_catalogue_file_stops_the_import(): void
    {
        $broken = tempnam(sys_get_temp_dir(), 'catalogue').'.json';
        file_put_contents($broken, '[{"id":"3_4_Sit-Up","name":"3/4 Si');

        $seeder = new class($broken) extends ExerciseCatalogueSeeder
        {
            public function __construct(private readonly string $file) {}

            protected function catalogueFile(): string
            {
                return $this->file;
            }
        };

        try {
            $this->expectException(JsonException::class);

            $seeder->run();
        } finally {
            unlink($broken);
        }
    }

    public function test_the_import_carries_instructions_and_no_image(): void
    {
        $this->seed(ExerciseCatalogueSeeder::class);

        $exercise = Exercise::query()->where('external_id', '3_4_Sit-Up')->firstOrFail();

        $this->assertSame('3/4 Sit-Up', $exercise->name);
        $this->assertSame(['abdominals'], $exercise->primary_muscles);
        $this->assertNotEmpty($exercise->instructions);
        $this->assertNull($exercise->image_path);
    }
}
