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

        $workoutTwo = Workout::create(

            [
                'name' => 'Lower Body One',
                'description' => 'Lower body workout for advanced',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutThree = Workout::create(

            [
                'name' => 'Upper Body Two',
                'description' => 'Upper body workout for intermediate',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutFour = Workout::create(

            [
                'name' => 'Lower Body Two',
                'description' => 'Lower body workout for beginners',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutFive = Workout::create(

            [
                'name' => 'Upper Body Three',
                'description' => 'Upper body workout for advanced',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );


       $workoutSix = Workout::create(

            [
                'name' => 'Lower Body Three',
                'description' => 'Lower body workout for intermediate',
                'category_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutOne->exercises()->attach([
            1,
            2,
            3,
            4,
            5
        ]);

        $workoutTwo->exercises()->attach([
            6,
            7,
            8,
            9,
        ]);

        $workoutThree->exercises()->attach([
            10,
            11,
            12,
            13,
            14,
            15
        ]);

        $workoutFour->exercises()->attach([
            16,
            17,
            18,
            19,
        ]);

        $workoutFive->exercises()->attach([
            20,
            21,
            22,
            23,
            24
        ]);

        $workoutSix->exercises()->attach([
            25,
            26,
            27,
            28,
        ]);
    }
}
