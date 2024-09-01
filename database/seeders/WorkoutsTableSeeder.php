<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Exercise;
use App\Models\Workout;


class WorkoutsTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workoutOne = Workout::create(
            [
                'name' => 'Upper Body One',
                'description' => 'Upper body workout for beginners',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],

        );
    
        Workout::create(

            [
                'name' => 'Upper Body Two',
                'description' => 'Upper body workout for intermediate',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Workout::create(

            [
                'name' => 'Lower Body One',
                'description' => 'Lower body workout for advanced',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Workout::create(

            [
                'name' => 'Lower Body Two',
                'description' => 'Lower body workout for beginners',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        Workout::factory()
            ->hasAttached(Exercise::factory()->count(3))
            ->count(3)
            ->create();

        $workoutOne->exercises()->attach([
            1,
            2,
            3,
            4
        ]);
    }
}
