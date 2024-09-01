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
        Exercise::create([
            'name' => 'Incline Dubbell Press',
            'description' => 'This exercise targets the upper chest',
            'sets' => 4,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Exercise::create([
            'name' => 'Chest Supported Row',
            'description' => 'This exercise targets the upper back',
            'sets' => 3,
            'reps' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
