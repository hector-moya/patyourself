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

        Muscle::create([
            'name' => 'Upper Chest',
            'description' => 'The upper chest is the clavicular head of the pectoralis major muscle. It is the muscle that is located on the upper part of the chest, just below the collarbone. The upper chest is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade. The upper chest is a very important muscle in the body, as it is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade. The upper chest is a very important muscle in the body, as it is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Upper Back',
            'description' => 'The upper back is the area of the back that is located above the waist.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Side Deltoids',
            'description' => 'The side deltoids are the muscles that are located on the side of the shoulder. They are responsible for the movement of the arm and the shoulder. The side deltoids are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade. The side deltoids are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Lats',
            'description' => 'The lats are the muscles that are located on the side of the back. They are responsible for the movement of the arm and the shoulder. The lats are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade. The lats are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Triceps',
            'description' => 'The triceps are the muscles that are located on the back of the arm. They are responsible for the movement of the arm and the shoulder. The triceps are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade. The triceps are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Lower Chest',
            'description' => 'The lower chest is the sternal head of the pectoralis major muscle. It is the muscle that is located on the lower part of the chest, just below the ribcage. The lower chest is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade. The lower chest is a very important muscle in the body, as it is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade. The lower chest is a very important muscle in the body, as it is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Lower Back',
            'description' => 'The lower back is the area of the back that is located below the waist.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Front Deltoids',
            'description' => 'The front deltoids are the muscles that are located on the front of the shoulder. They are responsible for the movement of the arm and the shoulder. The front deltoids are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade. The front deltoids are a very important muscle in the body, as they are responsible for the movement of the arm and the shoulder. They are also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Quads',
            'description' => 'The quads are the muscles that are located on the front of the thigh. They are responsible for the movement of the leg and the knee. The quads are a very important muscle in the body, as they are responsible for the movement of the leg and the knee. They are also responsible for the movement of the hip. The quads are a very important muscle in the body, as they are responsible for the movement of the leg and the knee. They are also responsible for the movement of the hip.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Hamstrings',
            'description' => 'The hamstrings are the muscles that are located on the back of the thigh. They are responsible for the movement of the leg and the knee. The hamstrings are a very important muscle in the body, as they are responsible for the movement of the leg and the knee. They are also responsible for the movement of the hip. The hamstrings are a very important muscle in the body, as they are responsible for the movement of the leg and the knee. They are also responsible for the movement of the hip.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Calves',
            'description' => 'The calves are the muscles that are located on the back of the lower leg. They are responsible for the movement of the foot and the ankle. The calves are a very important muscle in the body, as they are responsible for the movement of the foot and the ankle. They are also responsible for the movement of the knee. The calves are a very important muscle in the body, as they are responsible for the movement of the foot and the ankle. They are also responsible for the movement of the knee.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Middle Chest',
            'description' => 'The middle chest is the sternal head of the pectoralis major muscle. It is the muscle that is located on the middle part of the chest, just below the collarbone. The middle chest is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade. The middle chest is a very important muscle in the body, as it is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade. The middle chest is a very important muscle in the body, as it is responsible for the movement of the shoulder joint and the arm. It is also responsible for the movement of the shoulder blade.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Biceps',
            'description' => 'The biceps are muscles on the front of the upper arm responsible for flexing the elbow and supinating the forearm.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Forearms',
            'description' => 'The forearms comprise various muscles responsible for grip strength, wrist flexion, and extension.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Abs',
            'description' => 'The abs, or abdominal muscles, are crucial for core stability, posture, and spinal flexion.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Obliques',
            'description' => 'The obliques are located on the sides of the abdomen and contribute to core strength, rotation, and side bending.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Glutes',
            'description' => 'The glutes, or gluteal muscles, are the largest muscles in the body, responsible for hip extension and lower body power.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Muscle::create([
            'name' => 'Traps',
            'description' => 'The traps, or trapezius muscles, support the neck and upper back, involved in shrugging and overhead movements.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

    }
}
