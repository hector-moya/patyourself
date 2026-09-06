<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The shared exercise catalogue: global and user-independent, so it
        // runs before any user-scoped data exists.
        $this->call(ExerciseCatalogueSeeder::class);

        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Realistic habit graph (loops, versioned strategies, actions, logs, summaries).
        $this->call(HabitDataSeeder::class, parameters: ['user' => $user]);
    }
}
