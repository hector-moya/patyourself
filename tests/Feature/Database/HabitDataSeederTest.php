<?php

namespace Tests\Feature\Database;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Database\Seeders\HabitDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HabitDataSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedFor(User $user): void
    {
        // Call run() directly (not $this->seed) so the optional User argument
        // keeps its null default instead of being container-injected.
        (new HabitDataSeeder)->run($user);
    }

    public function test_it_builds_a_realistic_habit_graph_for_a_user()
    {
        $user = User::factory()->create();
        $this->seedFor($user);

        // 3 active loops + 1 paused loop.
        $this->assertSame(4, Intention::query()->where('user_id', $user->id)->count());
        $this->assertSame(
            1,
            Intention::query()->where('user_id', $user->id)->where('status', Intention::STATUS_PAUSED)->count(),
        );

        // Account-level rolling summary exists alongside the per-loop ones.
        $this->assertTrue(
            Summary::query()->where('user_id', $user->id)->where('scope', Summary::SCOPE_USER)->exists(),
        );
    }

    public function test_each_loop_keeps_a_versioned_strategy_history_with_one_active_version()
    {
        $this->seedFor(User::factory()->create());

        Intention::query()->with('strategies')->get()->each(function (Intention $intention) {
            $strategies = $intention->strategies;

            // v1 superseded (history preserved) + v2 active.
            $this->assertCount(2, $strategies);
            $this->assertSame(1, $strategies->where('status', Strategy::STATUS_ACTIVE)->count());

            $superseded = $strategies->firstWhere('status', Strategy::STATUS_SUPERSEDED);
            $this->assertNotNull($superseded);
            // A superseded version always records why it was retired.
            $this->assertNotNull($superseded->superseded_reason);

            $active = $intention->activeStrategy;
            // The restrategy shifted the intervention point upstream (response → cue).
            $this->assertSame(Strategy::POINT_CUE, $active->intervention_point);
            $this->assertSame($superseded->id, $active->parent_strategy_id);
        });
    }

    public function test_every_loop_records_at_least_one_failure_log()
    {
        $this->seedFor(User::factory()->create());

        $this->assertGreaterThanOrEqual(
            Intention::query()->count(),
            ActionLog::failures()->count(),
        );
    }
}
