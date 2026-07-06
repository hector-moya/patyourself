<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\TodayActionsTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayActionsToolTest extends TestCase
{
    use RefreshDatabase;

    private function loopFor(User $user, string $status = Intention::STATUS_ACTIVE): Intention
    {
        return Intention::factory()->for($user)->create(['status' => $status]);
    }

    public function test_lists_fired_and_due_today_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopFor($user);

        Action::factory()->for($loop)->create([
            'title' => 'Fired earlier today',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHours(2),
        ]);
        Action::factory()->for($loop)->create([
            'title' => 'Later today',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->endOfDay()->subMinutes(10),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('Fired earlier today')
            ->assertSee('due_now')
            ->assertSee('Later today')
            ->assertSee('upcoming');
    }

    public function test_includes_unscheduled_anchored_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'Anchored to brushing teeth',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => null,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('Anchored to brushing teeth');
    }

    public function test_excludes_tomorrows_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'Tomorrow only',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->addDay()->startOfDay()->addHours(9),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertDontSee('Tomorrow only');
    }

    public function test_excludes_actions_on_paused_loops(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user, Intention::STATUS_PAUSED))->create([
            'title' => 'Paused loop action',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHour(),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertDontSee('Paused loop action');
    }

    public function test_excludes_other_users_actions(): void
    {
        $foreignLoop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($foreignLoop)->create([
            'title' => 'Not yours',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHour(),
        ]);

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertDontSee('Not yours');
    }
}
