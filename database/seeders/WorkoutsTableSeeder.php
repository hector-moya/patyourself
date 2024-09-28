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
                'intensity' => 'low',
                'image_path' => 'u6FhFqnNTe0',
                'created_at' => now(),
                'updated_at' => now(),
            ],

        );

        $workoutTwo = Workout::create(

            [
                'name' => 'Lower Body One',
                'description' => 'Lower body workout for advanced',
                'category_id' => 1,
                'intensity' => 'high',
                'image_path' => 'optBC2FxCfc',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutThree = Workout::create(

            [
                'name' => 'Upper Body Two',
                'description' => 'Upper body workout for intermediate',
                'category_id' => 1,
                'intensity' => 'medium',
                'image_path' => 'G3YSKeUAqoc',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutFour = Workout::create(

            [
                'name' => 'Lower Body Two',
                'description' => 'Lower body workout for beginners',
                'category_id' => 1,
                'intensity' => 'low',
                'image_path' => 'WvDYdXDzkhs',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutFive = Workout::create(

            [
                'name' => 'Upper Body Three',
                'description' => 'Upper body workout for advanced',
                'category_id' => 1,
                'intensity' => 'high',
                'image_path' => 'ohQiBoCqViM',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );


       $workoutSix = Workout::create(

            [
                'name' => 'Lower Body Three',
                'description' => 'Lower body workout for intermediate',
                'category_id' => 1,
                'intensity' => 'medium',
                'image_path' => 'KTYkg4lzMlY',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $workoutOne->exercises()->attach([
            520,
            542,
            310,
            273,
        ]);

        $workoutTwo->exercises()->attach([
            130,
            699,
            983,
            994,
        ]);

        $workoutThree->exercises()->attach([
            99,
            280,
            367,
            521,
            721,
        ]);

        $workoutFour->exercises()->attach([
            108,
            556,
            975,
            1159,
        ]);

        $workoutFive->exercises()->attach([
            273,
            666,
            979,
            980,
            1009,
        ]);

        $workoutSix->exercises()->attach([
            955,
            956,
            994,
            1157,
        ]);
    }
}
