<?php

namespace Tests\Feature\Models;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_user_and_owns_strategies_actions_and_summaries()
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($intention)->create(['version' => 1]);
        Action::factory()->for($intention)->for($strategy, 'strategy')->create();
        Summary::factory()->for($user)->for($intention)->create();

        $this->assertTrue($intention->user->is($user));
        $this->assertCount(1, $intention->strategies);
        $this->assertCount(1, $intention->actions);
        $this->assertCount(1, $intention->summaries);
    }

    public function test_active_strategy_returns_only_the_latest_active_version()
    {
        $intention = Intention::factory()->create();
        Strategy::factory()->for($intention)->superseded()->create(['version' => 1]);
        $current = Strategy::factory()->for($intention)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $this->assertTrue($intention->activeStrategy->is($current));
    }

    public function test_the_active_scope_excludes_non_active_loops()
    {
        Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->create(['status' => Intention::STATUS_PAUSED]);
        Intention::factory()->create(['status' => Intention::STATUS_ARCHIVED]);

        $this->assertSame(1, Intention::active()->count());
    }

    public function test_direction_and_status_predicates()
    {
        $build = Intention::factory()->building()->create(['status' => Intention::STATUS_ACTIVE]);
        $break = Intention::factory()->breaking()->create(['status' => Intention::STATUS_PAUSED]);

        $this->assertTrue($build->isBuild());
        $this->assertFalse($build->isBreaking());
        $this->assertTrue($build->isActive());

        $this->assertTrue($break->isBreaking());
        $this->assertFalse($break->isActive());
    }

    public function test_next_strategy_version_is_monotonic()
    {
        $intention = Intention::factory()->create();

        $this->assertSame(1, $intention->nextStrategyVersion());

        Strategy::factory()->for($intention)->create(['version' => 1]);
        Strategy::factory()->for($intention)->create(['version' => 2, 'status' => Strategy::STATUS_SUPERSEDED]);

        $this->assertSame(3, $intention->nextStrategyVersion());
    }
}
