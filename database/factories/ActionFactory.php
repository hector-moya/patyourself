<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Action>
 */
class ActionFactory extends Factory
{
    protected $model = Action::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intention_id' => Intention::factory(),
            'strategy_id' => Strategy::factory(),
            'title' => fake()->randomElement([
                'Lay the book on your pillow each morning',
                'Put the snacks out of sight tonight',
                'Set your shoes by the door',
                'Leave your phone in another room',
                'Fill your water bottle first thing',
            ]),
            'description' => fake()->sentence(9),
            'series_started_at' => fake()->dateTimeBetween('-3 days', '+4 days'),
            'recurrence' => fake()->randomElement([null, 'daily', 'weekdays']),
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['schedule_kind' => 'clock', 'card' => ['style' => 'default']],
        ];
    }

    /** A cue-anchored action: no clock time, so no grid of occasions. */
    public function anchored(): static
    {
        return $this->state([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after brushing my teeth'],
        ]);
    }

    /** Removed from the loop's live set. `remove-action` archives, never deletes. */
    public function archived(): static
    {
        return $this->state(['status' => Action::STATUS_ARCHIVED]);
    }
}
