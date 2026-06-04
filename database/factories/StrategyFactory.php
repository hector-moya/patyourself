<?php

namespace Database\Factories;

use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Strategy>
 */
class StrategyFactory extends Factory
{
    protected $model = Strategy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intention_id' => Intention::factory(),
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => fake()->randomElement([
                Strategy::POINT_CUE,
                Strategy::POINT_CRAVING,
                Strategy::POINT_RESPONSE,
                Strategy::POINT_REWARD,
            ]),
            'approach' => fake()->sentence(12),
            'rationale' => fake()->sentence(14),
            'parent_strategy_id' => null,
            'change_reason' => Strategy::REASON_INITIAL,
            'superseded_reason' => null,
            'metadata' => null,
        ];
    }

    /** The first version of a loop's strategy. */
    public function initial(): static
    {
        return $this->state([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'change_reason' => Strategy::REASON_INITIAL,
            'parent_strategy_id' => null,
            'superseded_reason' => null,
        ]);
    }

    /** A version superseded by a later one — keeps the user-stated reason. */
    public function superseded(?string $reason = null): static
    {
        return $this->state([
            'status' => Strategy::STATUS_SUPERSEDED,
            'superseded_reason' => $reason ?? fake()->sentence(8),
        ]);
    }

    /** A new version created after a failure shifted the intervention point. */
    public function restrategized(): static
    {
        return $this->state([
            'change_reason' => Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
        ]);
    }

    /** A new version stacked after the previous one succeeded. */
    public function stacked(): static
    {
        return $this->state([
            'change_reason' => Strategy::REASON_STACKED_ON_SUCCESS,
        ]);
    }
}
