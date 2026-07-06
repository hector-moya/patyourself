<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\GetLoopTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetLoopToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_loop_with_its_strategy_timeline(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create([
            'title' => 'Read before bed',
            'cue' => 'After brushing teeth',
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
            'approach' => 'Book on the pillow',
        ]);
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'approach' => 'Phone charges outside the bedroom',
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(GetLoopTool::class, ['intention_id' => $loop->id])
            ->assertOk()
            ->assertSee('Read before bed')
            ->assertSee('After brushing teeth')
            ->assertSee('Book on the pillow')
            ->assertSee('Phone charges outside the bedroom');
    }

    public function test_rejects_an_unknown_loop(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(GetLoopTool::class, ['intention_id' => 999999])
            ->assertHasErrors(['Not found.']);
    }

    public function test_rejects_another_users_loop_identically(): void
    {
        $foreign = Intention::factory()->create();

        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(GetLoopTool::class, ['intention_id' => $foreign->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_requires_an_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(GetLoopTool::class)
            ->assertHasErrors();
    }
}
