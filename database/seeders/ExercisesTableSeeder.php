<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Exercise;

class ExercisesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        // Upper Body 1
        $exercise1 = Exercise::create([
            'name' => 'Incline Dubbell Press',
            'description' => 'This exercise targets the upper chest',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise2 = Exercise::create([
            'name' => 'Chest Supported Row',
            'description' => 'This exercise targets the upper back',
            'sets' => 3,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise3 = Exercise::create([
            'name' => 'Lean away cable lateral raise',
            'description' => 'This exercise targets the side deltoids',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise4 = Exercise::create([
            'name' => 'Lat Pull',
            'description' => 'This exercise targets the lats',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise5 = Exercise::create([
            'name' => 'Incline Overhead Dumbbell Extensions',
            'description' => 'This exercise targets the triceps',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lower Body 1

        $exercise6 = Exercise::create([
            'name' => 'Back Squat',
            'description' => 'This exercise targets the quads',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise7 = Exercise::create([
            'name' => 'Bulgarian Split Squat',
            'description' => 'This exercise targets the quads',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise8 = Exercise::create([
            'name' => 'Adductor',
            'description' => 'This exercise targets the adductors',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise9 = Exercise::create([
            'name' => 'Calf Extension',
            'description' => 'This exercise targets the calves',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Upper Body 2

        $exercise10 = Exercise::create([
            'name' => 'Barbell Bench Press',
            'description' => 'This exercise targets the chest',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise11 = Exercise::create([
            'name' => 'Seated Row',
            'description' => 'This exercise targets the upper back',
            'sets' => 3,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise12 = Exercise::create([
            'name' => 'Standing Overhead Press',
            'description' => 'This exercise targets the shoulders',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise13 = Exercise::create([
            'name' => 'Kneeling Facepulls',
            'description' => 'This exercise targets the rear deltoids',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise14 = Exercise::create([
            'name' => 'High to Low Cable Flies',
            'description' => 'This exercise targets the chest',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise15 = Exercise::create([
            'name' => 'Incline Dumbbell Curls',
            'description' => 'This exercise targets the biceps',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lower Body 2

        $exercise16 = Exercise::create([
            'name' => 'Deadlift',
            'description' => 'This exercise targets the hamstrings',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise17 = Exercise::create([
            'name' => 'Leg Press',
            'description' => 'This exercise targets the quads',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise18 = Exercise::create([
            'name' => 'Reverse Lunges',
            'description' => 'This exercise targets the quads per side',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise19 = Exercise::create([
            'name' => 'Seated Weighted Calf Raise',
            'description' => 'This exercise targets the calves',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Upper Body 3

        $exercise20 = Exercise::create([
            'name' => 'Pectoral Machine',
            'description' => 'This exercise targets the chest',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise21 = Exercise::create([
            'name' => 'PullDown',
            'description' => 'This exercise targets the lats',
            'sets' => 3,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise22 = Exercise::create([
            'name' => 'Chest Press',
            'description' => 'This exercise targets the chest',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise23 = Exercise::create([
            'name' => 'Seated Dip',
            'description' => 'This exercise targets the triceps',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise24 = Exercise::create([
            'name' => 'Seated Bicep',
            'description' => 'This exercise targets the biceps',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Lower Body 3

        $exercise25 = Exercise::create([
            'name' => 'Leg Curl',
            'description' => 'This exercise targets the hamstrings',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise26 = Exercise::create([
            'name' => 'Leg Extension',
            'description' => 'This exercise targets the quads',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise27 = Exercise::create([
            'name' => 'Leg Press',
            'description' => 'This exercise targets the quads',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise28 = Exercise::create([
            'name' => 'Seated Weighted Calf Raise',
            'description' => 'This exercise targets the calves',
            'sets' => 3,
            'reps' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exercise1->muscles()->attach([1, 3, 5]); // Incline Dumbbell Press: Upper Chest, Front Deltoids, Triceps
        $exercise2->muscles()->attach([2, 4]);     // Chest Supported Row: Upper Back, Lats
        $exercise3->muscles()->attach([3]);        // Lean away cable lateral raise: Side Deltoids
        $exercise4->muscles()->attach([4]);        // Lat Pull: Lats
        $exercise5->muscles()->attach([5]);        // Incline Overhead Dumbbell Extensions: Triceps
        $exercise6->muscles()->attach([9]);        // Back Squat: Quads
        $exercise7->muscles()->attach([9]);        // Bulgarian Split Squat: Quads
        $exercise8->muscles()->attach([]);         // Adductor: (No muscle specified in the provided data)
        $exercise9->muscles()->attach([11]);       // Calf Extension: Calves
        $exercise10->muscles()->attach([12]);      // Barbell Bench Press: Middle Chest
        $exercise11->muscles()->attach([2]);       // Seated Row: Upper Back
        $exercise12->muscles()->attach([3, 8]);     // Standing Overhead Press: Side Deltoids, Front Deltoids
        $exercise13->muscles()->attach([18]);      // Kneeling Facepulls: Traps (assuming rear deltoids are part of the broader shoulder/traps complex)
        $exercise14->muscles()->attach([12]);      // High to Low Cable Flies: Middle Chest
        $exercise15->muscles()->attach([13]);      // Incline Dumbbell Curls: Biceps
        $exercise16->muscles()->attach([10]);      // Deadlift: Hamstrings
        $exercise17->muscles()->attach([9]);        // Leg Press: Quads
        $exercise18->muscles()->attach([9]);        // Reverse Lunges: Quads
        $exercise19->muscles()->attach([11]);       // Seated Weighted Calf Raise: Calves
        $exercise20->muscles()->attach([12]);      // Pectoral Machine: Middle Chest
        $exercise21->muscles()->attach([4]);        // PullDown: Lats
        $exercise22->muscles()->attach([12]);      // Chest Press: Middle Chest
        $exercise23->muscles()->attach([5]);        // Seated Dip: Triceps
        $exercise24->muscles()->attach([13]);      // Seated Bicep: Biceps
        $exercise25->muscles()->attach([10]);      // Leg Curl: Hamstrings
        $exercise26->muscles()->attach([9]);        // Leg Extension: Quads
        $exercise27->muscles()->attach([9]);        // Leg Press: Quads
        $exercise28->muscles()->attach([11]);       // Seated Weighted Calf Raise: Calves

    }
}
