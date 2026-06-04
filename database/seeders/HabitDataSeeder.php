<?php

namespace Database\Seeders;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Builds a realistic habit graph for a user: a few loops, each with a
 * versioned strategy history (an initial version that was restrategized after
 * a logged failure), actions bound to the version that produced them, a mix of
 * completed / failed logs, and rolling summaries.
 */
class HabitDataSeeder extends Seeder
{
    public function run(?User $user = null): void
    {
        $user ??= User::factory()->create();

        Intention::factory()
            ->count(3)
            ->for($user)
            ->create()
            ->each(fn (Intention $intention) => $this->seedLoop($user, $intention));

        // One paused loop and one completed loop for variety.
        $this->seedLoop($user, Intention::factory()->for($user)->create([
            'status' => Intention::STATUS_PAUSED,
        ]));

        // Account-level rolling summary across everything.
        Summary::factory()->userScope()->for($user)->create([
            'content' => 'Strong on morning habits; evening routines slip when the day runs late. '
                .'Cue-based reminders are landing better than willpower-based ones.',
            'events_count' => 24,
        ]);
    }

    /**
     * Seed a single loop's versioned strategy history plus actions and logs.
     */
    protected function seedLoop(User $user, Intention $intention): void
    {
        // v1 — restrategized away after a failure, but kept in history.
        $failureReason = 'Kept missing it because the cue came too late in the day';

        $v1 = Strategy::factory()
            ->for($intention)
            ->initial()
            ->superseded($failureReason)
            ->create([
                'version' => 1,
                'intervention_point' => Strategy::POINT_RESPONSE,
            ]);

        // v2 — the active version, shifted earlier in the chain (to the cue).
        $v2 = Strategy::factory()
            ->for($intention)
            ->restrategized()
            ->create([
                'version' => 2,
                'status' => Strategy::STATUS_ACTIVE,
                'parent_strategy_id' => $v1->id,
                'intervention_point' => Strategy::POINT_CUE,
            ]);

        // v1 actions: completed history + the failure that triggered the shift.
        $v1Action = Action::factory()
            ->for($intention)
            ->for($v1, 'strategy')
            ->completed()
            ->create();

        ActionLog::factory()->for($v1Action, 'action')->for($user)->completed()->count(3)->create();
        ActionLog::factory()->for($v1Action, 'action')->for($user)->failed($failureReason)->create([
            'logged_at' => now()->subDays(8),
        ]);

        // v2 actions: currently active, mostly going well.
        Action::factory()
            ->for($intention)
            ->for($v2, 'strategy')
            ->count(2)
            ->create()
            ->each(function (Action $action) use ($user) {
                ActionLog::factory()->for($action, 'action')->for($user)->completed()->count(2)->create();
                if (fake()->boolean(40)) {
                    ActionLog::factory()->for($action, 'action')->for($user)->failed()->create();
                }
            });

        // Intention-scoped rolling summary.
        Summary::factory()->for($user)->for($intention)->create([
            'events_count' => 6,
        ]);
    }
}
