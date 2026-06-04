<?php

namespace Database\Factories;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Intention>
 */
class IntentionFactory extends Factory
{
    protected $model = Intention::class;

    /**
     * Realistic habit loops, modelled as the cue -> craving -> response ->
     * reward chain.
     *
     * @var list<array<string, string>>
     */
    protected array $loops = [
        [
            'title' => 'Read before bed',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Phone goes on the charger at 10pm',
            'craving' => 'Wind down without a screen',
            'response' => 'Read one chapter of a paper book',
            'reward' => 'Calmer, falls asleep faster',
        ],
        [
            'title' => 'Stop late-night snacking',
            'type' => Intention::TYPE_BREAK,
            'cue' => 'Sitting on the couch after dinner',
            'craving' => 'Something sweet while watching TV',
            'response' => 'Walks to the kitchen for a snack',
            'reward' => 'Brief comfort, then guilt',
        ],
        [
            'title' => 'Morning walk',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Coffee finishes brewing',
            'craving' => 'Feel awake and clear-headed',
            'response' => 'Take a 15-minute walk around the block',
            'reward' => 'Energy and a sense of momentum',
        ],
        [
            'title' => 'Less doomscrolling',
            'type' => Intention::TYPE_BREAK,
            'cue' => 'A spare moment / boredom',
            'craving' => 'A quick hit of novelty',
            'response' => 'Open a social app and scroll',
            'reward' => 'Distraction, then drained',
        ],
        [
            'title' => 'Drink more water',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Sitting down at the desk',
            'craving' => 'Stay focused and hydrated',
            'response' => 'Fill and keep a water bottle in reach',
            'reward' => 'Fewer headaches, steadier focus',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loop = fake()->randomElement($this->loops);

        return [
            'user_id' => User::factory(),
            'title' => $loop['title'],
            'description' => fake()->sentence(10),
            'type' => $loop['type'],
            'status' => Intention::STATUS_ACTIVE,
            'cue' => $loop['cue'],
            'craving' => $loop['craving'],
            'response' => $loop['response'],
            'reward' => $loop['reward'],
            'metadata' => ['authored_by' => 'seed', 'confidence' => fake()->randomFloat(2, 0.6, 0.95)],
        ];
    }

    public function building(): static
    {
        return $this->state(['type' => Intention::TYPE_BUILD]);
    }

    public function breaking(): static
    {
        return $this->state(['type' => Intention::TYPE_BREAK]);
    }

    public function completed(): static
    {
        return $this->state(['status' => Intention::STATUS_COMPLETED]);
    }
}
