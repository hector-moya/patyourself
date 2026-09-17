<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionNode>
 */
class CompanionNodeFactory extends Factory
{
    protected $model = CompanionNode::class;

    /**
     * Met but empty, which is what an encounter leaves behind before the skill
     * is learned.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'node' => 'reeds',
            'available' => 0,
        ];
    }
}
