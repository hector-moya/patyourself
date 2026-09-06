<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionExercise>
 */
class ActionExerciseFactory extends Factory
{
    protected $model = ActionExercise::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_id' => Action::factory(),
            'exercise_id' => Exercise::factory(),
            'position' => 1,
            'target_sets' => 3,
            'target_reps' => fake()->numberBetween(5, 12),
        ];
    }
}
