<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActionLog>
 */
class ActionLogFactory extends Factory
{
    protected $model = ActionLog::class;

    /**
     * @var list<string>
     */
    protected array $failureReasons = [
        'Got home too late and was exhausted',
        'Forgot until I was already in bed',
        'Friends came over unexpectedly',
        'Felt too stressed to bother',
        'The cue never really happened today',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $loggedAt = fake()->dateTimeBetween('-2 weeks', 'now');

        return [
            'action_id' => Action::factory(),
            'user_id' => User::factory(),
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'reason' => null,
            'logged_at' => $loggedAt,
            'metadata' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'reason' => null,
        ]);
    }

    /** A failure always carries the user-stated reason. */
    public function failed(?string $reason = null): static
    {
        return $this->state(fn () => [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason ?? fake()->randomElement($this->failureReasons),
        ]);
    }

    public function skipped(): static
    {
        return $this->state([
            'outcome' => ActionLog::OUTCOME_SKIPPED,
            'reason' => fake()->optional()->sentence(6),
        ]);
    }
}
