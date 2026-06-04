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
            'scheduled_for' => fake()->dateTimeBetween('-3 days', '+4 days'),
            'recurrence' => fake()->randomElement([null, 'daily', 'weekdays']),
            'status' => Action::STATUS_ACTIVE,
            'metadata' => ['card' => ['style' => 'default']],
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => Action::STATUS_PENDING]);
    }

    public function completed(): static
    {
        return $this->state(['status' => Action::STATUS_COMPLETED]);
    }
}
