<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<ActionExercise>
 */
class ActionExerciseFactory extends Factory
{
    protected $model = ActionExercise::class;

    /**
     * `position` is deliberately absent — {@see self::configure()} supplies it,
     * because a constant here cannot satisfy the unique index.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_id' => Action::factory(),
            'exercise_id' => Exercise::factory(),
            'target_sets' => 3,
            'target_reps' => fake()->numberBetween(5, 12),
        ];
    }

    /**
     * Walks each batch's positions 1, 2, 3…
     *
     * `(action_id, position)` is unique, so a constant default makes
     * `->count(3)->create(['action_id' => $action->id])` collide on the index —
     * and a routine is a list, so building one is the ordinary case rather than
     * an exotic one worth an opt-in state. A single `->create()` still lands on
     * position 1, which is what every caller before this got.
     */
    public function configure(): static
    {
        return $this->sequence(
            fn (Sequence $sequence): array => ['position' => $sequence->index + 1],
        );
    }
}
