<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<PerformedSet>
 */
class PerformedSetFactory extends Factory
{
    protected $model = PerformedSet::class;

    /**
     * `set_number` is deliberately absent — {@see self::configure()} supplies
     * it, because a constant here cannot satisfy the unique index.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'occurrence_id' => Occurrence::factory(),
            'exercise_id' => Exercise::factory(),
            'reps' => fake()->numberBetween(5, 12),
            'weight' => fake()->randomFloat(2, 20, 120),
        ];
    }

    /**
     * Walks each batch's set numbers 1, 2, 3…
     *
     * `(occurrence_id, exercise_id, set_number)` is unique, so a constant
     * default makes `->count(5)->create([...])` collide on the index — and a
     * session is several sets of the same movement, so that is the ordinary
     * case rather than an exotic one worth an opt-in state. A single
     * `->create()` still lands on set 1, which is what every caller before this
     * got.
     */
    public function configure(): static
    {
        return $this->sequence(
            fn (Sequence $sequence): array => ['set_number' => $sequence->index + 1],
        );
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
