<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionStashItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionStashItem>
 */
class CompanionStashItemFactory extends Factory
{
    protected $model = CompanionStashItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'companion_id' => Companion::factory(),
            'item' => 'fibre',
            'quantity' => 1,
        ];
    }
}
