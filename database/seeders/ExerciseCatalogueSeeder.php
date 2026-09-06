<?php

namespace Database\Seeders;

use App\Models\Exercise;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Imports the shared exercise catalogue from `database/data/exercises.json`.
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
     */
    public function run(): void
    {
        $records = File::json(database_path('data/exercises.json'));

        collect($records)->chunk(100)->each(function ($chunk): void {
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
}
