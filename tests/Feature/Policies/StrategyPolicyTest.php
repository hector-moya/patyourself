<?php

namespace Tests\Feature\Policies;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_may_update_a_strategy_on_their_own_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create();

        $this->assertTrue($user->can('update', $strategy));
    }

    public function test_a_stranger_may_not_update_a_strategy(): void
    {
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for(User::factory())->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create();

        $this->assertFalse($stranger->can('update', $strategy));
    }
}
