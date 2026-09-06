<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Imports the shared exercise catalogue from `database/data/exercises.json`.
 *
 * **Run this explicitly on deploy:**
 *
 *     php artisan db:seed --class=ExerciseCatalogueSeeder
 *
 * It is not wired into the deploy script and it cannot be reached through
 * `DatabaseSeeder`, which creates a `Test User` and so must never run in
 * production. Without this command the `exercises` table is empty and there is
 * nothing to build a routine from — the gym module has no catalogue of its own
 * to fall back on. Repeating it is safe by design (see the idempotency note
 * below), so "run it on every deploy" is a perfectly good rule.
 *
 * The data is a one-time, committed export of the free-exercise-db project
 * (https://github.com/yuhonas/free-exercise-db), licensed under The
 * Unlicense (public-domain equivalent). It is read from disk here, never
 * fetched at runtime.
 *
 * The source's `images` key is deliberately ignored: v1 ships no exercise
 * images, and every imported row gets an explicit null `image_path`.
 *
 * Idempotent on `external_id` (the source's own slug, e.g. `3_4_Sit-Up`), so
 * re-running this seeder on a later deploy updates the catalogue in place
 * instead of duplicating it. Each row goes through `updateOrCreate()` rather
 * than a single bulk `upsert()` call, because `upsert()` bypasses Eloquent's
 * attribute casting and would need the three JSON columns encoded by hand.
 * Records are still processed in chunks of 100, one `updateOrCreate()` call
 * per row within each chunk, to keep the import working in bounded batches.
 */
class ExerciseCatalogueSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * `JSON_THROW_ON_ERROR` is the difference between a truncated or malformed
     * catalogue file stopping the deploy and it seeding nothing at all: without
     * it `json_decode` returns null, `collect(null)` is an empty collection, and
     * the import reports success over an empty table.
     */
    public function run(): void
    {
        /** @var list<array<string, mixed>> $records */
        $records = File::json($this->catalogueFile(), JSON_THROW_ON_ERROR);

        collect($records)->chunk(100)->each(function (Collection $chunk): void {
            $chunk->each(function (array $record): void {
                Exercise::query()->updateOrCreate(
                    ['external_id' => $record['id']],
                    [
                        'user_id' => null,
                        'name' => $record['name'],
                        'category' => $record['category'] ?? null,
                        'equipment' => $record['equipment'] ?? null,
                        'force' => $record['force'] ?? null,
                        'mechanic' => $record['mechanic'] ?? null,
                        'level' => $record['level'] ?? null,
                        'primary_muscles' => $record['primaryMuscles'] ?? [],
                        'secondary_muscles' => $record['secondaryMuscles'] ?? [],
                        'instructions' => $record['instructions'] ?? [],
                        'image_path' => null,
                    ]
                );
            });
        });
    }

    /**
     * The committed export this seeder imports. A method rather than an inline
     * `database_path()` call so a test can point the import at a deliberately
     * broken file without going anywhere near the real one.
     */
    protected function catalogueFile(): string
    {
        return database_path('data/exercises.json');
    }
}
