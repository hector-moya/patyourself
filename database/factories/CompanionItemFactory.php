<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\CompanionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionItem>
 */
class CompanionItemFactory extends Factory
{
    protected $model = CompanionItem::class;

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
