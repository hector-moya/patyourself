<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Muscle;
use App\Models\Exercise;
use App\Models\Workout;

class MusclesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Muscle::factory()
            ->hasAttached(Exercise::factory()->count(3))
            ->hasAttached(Workout::factory()->count(3))
            ->count(3)
            ->create();
    }
}
