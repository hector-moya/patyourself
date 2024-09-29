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
            ]);

            // Attach secondary muscles
            $exercise->muscles()->attach($secondaryMuscleIds);
        }
    }
}
