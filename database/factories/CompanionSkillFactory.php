<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionSkill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionSkill>
 */
class CompanionSkillFactory extends Factory
{
    protected $model = CompanionSkill::class;

    /**
     * Learned now, so a node stocked by anything logged afterwards fills up —
     * a test wanting an established skill passes its own `learned_at`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'name' => 'gather-fibre',
            'learned_at' => now(),
        ];
    }
}
