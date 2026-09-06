<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PerformedSet>
 */
class PerformedSetFactory extends Factory
{
    protected $model = PerformedSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurrence_id' => Occurrence::factory(),
            'exercise_id' => Exercise::factory(),
            'set_number' => 1,
            'reps' => fake()->numberBetween(5, 12),
            'weight' => fake()->randomFloat(2, 20, 120),
        ];
    }

    /**
     * A body-weight set. Null, never zero: zero is a weight, and a
     * progression read cannot tell "no load" from "no load recorded".
     */
    public function bodyWeight(): static
    {
        return $this->state(['weight' => null]);
    }
}
