<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Objective;

class ObjectivesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Objective::create([
            'name' => 'Strength',
            'description' => 'Increase muscle strength',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        Objective::factory()
            ->count(3)
            ->create();

    }
}
