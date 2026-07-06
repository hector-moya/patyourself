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

    public function test_respects_the_users_timezone_for_the_today_boundary(): void
    {
        $this->travelTo(now()->parse('2026-07-06 12:00:00', 'UTC'));

        // For a Tokyo user (UTC+9), "today" ends at 2026-07-06 14:59:59 UTC.
        $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
        $loop = $this->loopFor($user);

        Action::factory()->for($loop)->create([
            'title' => 'Still today in Tokyo',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => '2026-07-06 14:00:00', // 23:00 JST — today
        ]);
        Action::factory()->for($loop)->create([
            'title' => 'Already tomorrow in Tokyo',
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => '2026-07-06 16:00:00', // 01:00 JST July 7 — tomorrow
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('Still today in Tokyo')
            ->assertDontSee('Already tomorrow in Tokyo');
    }

    public function test_falls_back_to_the_app_timezone_when_the_user_has_none(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'No timezone set',
            'status' => Action::STATUS_ACTIVE,
            'scheduled_for' => now()->subHour(),
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('No timezone set');
    }
}
