<?php

namespace Database\Factories;

use App\Models\Intention;
use App\Models\Note;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    protected $model = Note::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'intention_id' => Intention::factory(),
            'body' => fake()->randomElement([
                'Worse on the days I skip lunch',
                'Barely think about it when someone else serves',
                'The pan being on the table is what does it',
                'Easier on weekends, harder after a long day',
            ]),
            'noted_at' => fake()->dateTimeBetween('-2 weeks', 'now'),
        ];
    }
}
