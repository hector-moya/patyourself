<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Hector Moya',
            'email' => 'nokure@gmail.com',
            'password' => bcrypt('password'),
        ]);

        User::create([
            'name' => 'Alicia Gazmuri',
            'email' => 'aliciagazmuri@gmail.com',
            'password' => bcrypt('password'),
        ]);
    }
}
