<?php

namespace Database\Factories;

use App\Models\Companion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Companion>
 */
class CompanionFactory extends Factory
{
    protected $model = Companion::class;

    /**
     * Unnamed and unspent: a companion that has chosen nothing yet, which is
     * where every account starts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => null,
            'xp_spent' => 0,
        ];
    }

    /** A companion somebody has renamed. */
    public function named(string $name = 'Pebble'): static
    {
        return $this->state(['name' => $name]);
    }
}
