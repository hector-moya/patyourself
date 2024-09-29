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
            520 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            542 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            310 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            273 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
        ]);

        $workoutTwo->exercises()->attach([
            130 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            699 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            983 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            994 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
        ]);

        $workoutThree->exercises()->attach([
            99 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            280 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            367 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            521 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            721 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
        ]);

        $workoutFour->exercises()->attach([
            108 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            556 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            975 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            1159 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
        ]);

        $workoutFive->exercises()->attach([
            273 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            666 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            979 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            980 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            1009 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
        ]);

        $workoutSix->exercises()->attach([
            955 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            956 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            994 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
            1157 => ['sets' => 3, 'reps' => 10, 'weight' => 50, 'intensity' => 'low'],
        ]);
    }
}
