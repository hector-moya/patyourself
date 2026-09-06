<?php

namespace Database\Factories;

use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    protected $model = Exercise::class;

    /** Real movement names, so a factory-built exercise reads like a catalogue entry rather than gibberish. */
    private const NAMES = [
        'Barbell Bench Press',
        'Barbell Back Squat',
        'Conventional Deadlift',
        'Overhead Press',
        'Pull-Up',
        'Barbell Row',
        'Dumbbell Lateral Raise',
        'Walking Lunge',
        'Plank',
        'Kettlebell Swing',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'external_id' => null,
            'name' => fake()->randomElement(self::NAMES),
            'category' => fake()->randomElement(['strength', 'stretching', 'cardio', 'plyometrics']),
            'equipment' => fake()->randomElement(['barbell', 'dumbbell', 'body only', 'machine', 'kettlebells']),
            'force' => fake()->randomElement(['push', 'pull', 'static']),
            'mechanic' => fake()->randomElement(['compound', 'isolation']),
            'level' => fake()->randomElement(['beginner', 'intermediate', 'expert']),
            'primary_muscles' => fake()->randomElements(['chest', 'back', 'quadriceps', 'shoulders', 'hamstrings', 'abdominals'], 1),
            'secondary_muscles' => fake()->randomElements(['triceps', 'biceps', 'glutes', 'calves'], 2),
            'instructions' => [fake()->sentence(12), fake()->sentence(10)],
            'image_path' => null,
        ];
    }

    /**
     * An imported catalogue entry, carrying the source's own slug as its
     * external id. Drawn from a unique faker sequence rather than derived
     * from `name` — the name list above repeats, and `external_id` is unique
     * in the schema.
     */
    public function fromCatalogue(): static
    {
        return $this->state(fn (): array => [
            'external_id' => fake()->unique()->slug(3),
        ]);
    }
}
