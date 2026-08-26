<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\Occurrence;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Occurrence>
 */
class OccurrenceFactory extends Factory
{
    protected $model = Occurrence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_id' => Action::factory(),
            'scheduled_for' => fake()->dateTimeBetween('-2 weeks', 'now'),
        ];
    }

    /** An occasion that has already passed and nobody has logged yet. */
    public function unlogged(): static
    {
        return $this->state(['scheduled_for' => now()->subDay()]);
    }
}
