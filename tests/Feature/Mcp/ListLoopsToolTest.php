<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\ListLoopsTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListLoopsToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_loops_with_their_strategy_version(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'title' => 'Meditate every morning',
            'status' => Intention::STATUS_ACTIVE,
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(ListLoopsTool::class);

        $response->assertOk()
            ->assertSee('Meditate every morning')
            ->assertSee('"active_strategy_version":2');
    }

    public function test_excludes_paused_loops_by_default(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create([
            'title' => 'Paused loop',
            'status' => Intention::STATUS_PAUSED,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(ListLoopsTool::class)
            ->assertOk()
            ->assertDontSee('Paused loop');
    }

    public function test_status_all_returns_every_loop(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create([
            'title' => 'Paused loop',
            'status' => Intention::STATUS_PAUSED,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(ListLoopsTool::class, ['status' => 'all'])
            ->assertOk()
            ->assertSee('Paused loop');
    }

    public function test_never_lists_another_users_loops(): void
    {
        Intention::factory()->create(['title' => 'Someone elses loop']);

        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(ListLoopsTool::class)
            ->assertOk()
            ->assertDontSee('Someone elses loop');
    }

    public function test_rejects_an_unknown_status(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(ListLoopsTool::class, ['status' => 'exploded'])
            ->assertHasErrors();
    }
}
