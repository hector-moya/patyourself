<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Exercise;
use App\Models\Muscle;
use App\Models\Objective;
use App\Models\Plan;
use App\Models\Category;
use App\Models\Workout;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory(10)->withPersonalTeam()->create();

        User::factory()->withPersonalTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call([
            UsersTableSeeder::class,
            ObjectivesTableSeeder::class,
            CategoriesTableSeeder::class,
            MusclesTableSeeder::class,
            ExercisesTableSeeder::class,
            WorkoutsTableSeeder::class,
            PlansTableSeeder::class,
        ]);



    }
}
