<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Plan;
use App\Models\Workout;
use App\Models\User;

class PlansTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $beginnerPlan = Plan::create([
            'name' => 'Beginner Phase 2',
            'description' => 'This plan is for beginners who have completed the Beginner Phase 1 plan.',
            'objective_id' => 1,
            'image_path' => 'wd2vDD2n_xo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beginnerPlan->workouts()->attach([
            1,
            2,
            3,
            4,
            5,
            6
        ]);

        $testUserOne = User::where('email', 'nokure@gmail.com')->firstOrFail();
        $testUserOne->enrolledExcersisePlan()->attach($beginnerPlan); 

        $testUserTwo = User::where('email', 'aliciagazmuri@gmail.com')->firstOrFail();
        $testUserTwo->enrolledExcersisePlan()->attach($beginnerPlan);
    }
}
