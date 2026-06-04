<?php

namespace Database\Factories;

use App\Models\Intention;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Summary>
 */
class SummaryFactory extends Factory
{
    protected $model = Summary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-3 weeks', '-1 week');
        $end = fake()->dateTimeBetween($start, 'now');

        return [
            'user_id' => User::factory(),
            'intention_id' => Intention::factory(),
            'scope' => Summary::SCOPE_INTENTION,
            'content' => fake()->paragraph(3),
            'window_start' => $start,
            'window_end' => $end,
            'events_count' => fake()->numberBetween(3, 20),
            'metadata' => null,
        ];
    }

    /** An account-level rolling summary across all intentions. */
    public function userScope(): static
    {
        return $this->state([
            'scope' => Summary::SCOPE_USER,
            'intention_id' => null,
        ]);
    }
}
