<?php

namespace Database\Factories;

use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanionRemark>
 */
class CompanionRemarkFactory extends Factory
{
    protected $model = CompanionRemark::class;

    /**
     * The bodies follow the same copy rules the tool enforces: sentence case,
     * one or two sentences, no exclamation marks, about Blob rather than about
     * the person reading.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'intention_id' => Intention::factory(),
            'body' => fake()->randomElement([
                'Blob has been standing by the window a lot this week.',
                'Blob moved the rug twice and put it back where it was.',
                'Blob read the same page for an hour and seems content about it.',
                'Blob has taken to sitting near the door in the afternoons.',
            ]),
        ];
    }
}
