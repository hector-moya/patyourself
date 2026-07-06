<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LoopProgressTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoopProgressToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_totals_and_completion_rate(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['title' => 'Meditate']);
        $action = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_COMPLETED,
            'recurrence' => null,
            'scheduled_for' => null,
        ]);

        ActionLog::factory()->count(3)->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Too tired',
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(LoopProgressTool::class, ['intention_id' => $loop->id])
            ->assertOk()
            ->assertSee('Meditate')
            ->assertSee('"completed":3')
            ->assertSee('"failed":1');
    }

    public function test_rejects_another_users_loop(): void
    {
        $foreign = Intention::factory()->create();

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class, ['intention_id' => $foreign->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_requires_an_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class)
            ->assertHasErrors();
    }
}
