<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Category;

class CategoriesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Category::create([
            'name' => 'Gym',
            'description' => 'Exercises that require gym equipment',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Category::factory()
            ->count(3)
            ->create();
    }
}
