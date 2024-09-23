<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Exercise;
use App\Models\Muscle;

class ExercisesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // Upper Body 1
        // Exercise::create([
        //     'exercisedb_id' => 0314,
        //     'name' => 'dumbbell incline bench press',
        //     'description' => 'This exercise targets the upper chest',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'image_path' => 'u6FhFqnNTe0',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0327,
        //     'name' => 'dumbbell incline row',
        //     'description' => 'This exercise targets the upper back',
        //     'sets' => 3,
        //     'reps' => 10,
        //     'image_path' => '7oPvdWF9gi0',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0178,
        //     'name' => 'cable lateral raise',
        //     'description' => 'This exercise targets the side deltoids',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'name' => 'cable bar lateral pulldown',
        //     'description' => 'This exercise targets the lats',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'image_path' => '5zrPlR-5lP0',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0150,
        //     'name' => 'cable high pulley overhead tricep extension',
        //     'description' => 'This exercise targets the triceps',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => 'k95uqdEe8R4',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // // Lower Body 1

        // Exercise::create([
        //     'exercisedb_id' => 1436,
        //     'name' => 'barbell high bar squat',
        //     'description' => 'This exercise targets the quads',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'image_path' => '5_G4i0NRLx4',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0410,
        //     'name' => 'dumbbell single leg split squat',
        //     'description' => 'This exercise targets the quads',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => '3qZt1MwF4Zo',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0598,
        //     'name' => 'lever seated hip adduction',
        //     'description' => 'This exercise targets the adductors',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => 'IgEQ9Gx4jKM',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0605,
        //     'name' => 'lever standing calf raise',
        //     'description' => 'This exercise targets the calves',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // // Upper Body 2

        // Exercise::create([
        //     'exercisedb_id' => 0025,
        //     'name' => 'barbell bench press',
        //     'description' => 'This exercise targets the chest',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'image_path' => 'i2GS_MtW9hM',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 1323,
        //     'name' => 'cable rope seated row',
        //     'description' => 'This exercise targets the upper back',
        //     'sets' => 3,
        //     'reps' => 10,
        //     'image_path' => 'G3YSKeUAqoc',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0426,
        //     'name' => 'dumbbell standing overhead press',
        //     'description' => 'This exercise targets the shoulders',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => 'fl0TOkRZa4k',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0155,
        //     'name' => 'cable cross-over variation',
        //     'description' => 'This exercise targets the chest',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => 'wXBK9JrM0iU',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0315,
        //     'name' => 'dumbbell incline biceps curl',
        //     'description' => 'This exercise targets the biceps',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => '2yKcNJFwxug',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // // Lower Body 2

        // Exercise::create([
        //     'exercisedb_id' => 0032,
        //     'name' => 'barbell deadlift',
        //     'description' => 'This exercise targets the hamstrings',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'image_path' => 'j_YmEH6sB38',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0739,
        //     'name' => 'sled 45в° leg press',
        //     'description' => 'This exercise targets the quads',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => '6k1Zv6Z9W5A',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0336,
        //     'name' => 'dumbbell lunge',
        //     'description' => 'This exercise targets the quads per side',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => 'ujV0eawGWEA',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0594,
        //     'name' => 'lever seated calf raise',
        //     'description' => 'This exercise targets the calves',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // // Upper Body 3

        // Exercise::create([
        //     'exercisedb_id' => 1301,
        //     'name' => 'machine inner chest press',
        //     'description' => 'This exercise targets the chest',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'image_path' => 'ohQiBoCqViM',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0150,
        //     'name' => 'cable bar lateral pulldown',
        //     'description' => 'This exercise targets the lats',
        //     'sets' => 3,
        //     'reps' => 10,
        //     'image_path' => 'X2WCrBMeuY',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0596,
        //     'name' => 'lever seated fly',
        //     'description' => 'This exercise targets the chest',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 1451,
        //     'name' => 'lever seated dip',
        //     'description' => 'This exercise targets the triceps',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 1677,
        //     'name' => 'dumbbell seated bicep curl',
        //     'description' => 'This exercise targets the biceps',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // // Lower Body 3

        // Exercise::create([
        //     'exercisedb_id' => 0586,
        //     'name' => 'lever lying leg curl',
        //     'description' => 'This exercise targets the hamstrings',
        //     'sets' => 4,
        //     'reps' => 10,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 0585,
        //     'name' => 'lever leg extension',
        //     'description' => 'This exercise targets the quads',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Exercise::create([
        //     'exercisedb_id' => 1425,
        //     'name' => 'sled 45 degrees one leg press',
        //     'description' => 'This exercise targets the quads',
        //     'sets' => 3,
        //     'reps' => 12,
        //     'image_path' => 'wUyiLNXnNHY',
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);

        // Read the JSON file
        $jsonData = file_get_contents(database_path('exercisedb/exercises.json'));
        $exercisesData = json_decode($jsonData, true); // Decode into an associative array

        // Create a map to store muscle names and their IDs for efficient lookup
        $muscleMap = Muscle::pluck('id', 'name')->toArray();

        foreach ($exercisesData as $exerciseData) {
            // Extract and process data
            $exerciseId = $exerciseData['id'];
            $name = $exerciseData['name'];
            $description = json_encode($exerciseData['instructions']); // Store instructions as JSON
            $targetMuscleName = $exerciseData['target'];
            $secondaryMuscleNames = $exerciseData['secondaryMuscles'];
            $imagePath = $exerciseData['gifUrl'];

            // Find or create the target muscle
            $targetMuscleId = $muscleMap[$targetMuscleName] ?? Muscle::create(['name' => $targetMuscleName])->id;
            $muscleMap[$targetMuscleName] = $targetMuscleId; // Update the map

            // Find or create secondary muscles
            $secondaryMuscleIds = [];
            foreach ($secondaryMuscleNames as $muscleName) {
                $secondaryMuscleIds[] = $muscleMap[$muscleName] ?? Muscle::create(['name' => $muscleName])->id;
                $muscleMap[$muscleName] = $secondaryMuscleIds[count($secondaryMuscleIds) - 1]; // Update the map
            }

            // Create the exercise
            $exercise = Exercise::create([
                'exercisedb_id' => $exerciseId,
                'name' => $name,
                'description' => $description,
                'target_muscle_id' => $targetMuscleId,
                'image_path' => $imagePath,
                // You might need to adjust 'sets' and 'reps' handling based on your JSON structure
                'sets' => 3, // Default or extract from JSON if available
                'reps' => 10, // Default or extract from JSON if available
            ]);

            // Attach secondary muscles
            $exercise->muscles()->attach($secondaryMuscleIds);
        }
    }
}
