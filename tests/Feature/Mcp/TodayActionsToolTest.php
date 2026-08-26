<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\TodayActionsTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class TodayActionsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

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
            'series_started_at' => now()->subHours(2),
            'recurrence' => null,
        ]);
        Action::factory()->for($loop)->create([
            'title' => 'Later today',
            'series_started_at' => now()->endOfDay()->subMinutes(10),
            'recurrence' => null,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(TodayActionsTool::class);

        $response->assertOk();

        $payload = $this->payload($response);

        foreach ($payload as $item) {
            $this->assertSame([
                'id', 'occurrence_id', 'loop_id', 'loop_title', 'title', 'description', 'due', 'scheduled_for', 'recurrence',
            ], array_keys($item));
        }

        $fired = collect($payload)->firstWhere('title', 'Fired earlier today');
        $pending = collect($payload)->firstWhere('title', 'Later today');

        $this->assertNotNull($fired);
        $this->assertNotNull($pending);
        $this->assertSame('due_now', $fired['due']);
        $this->assertSame('upcoming', $pending['due']);
        $this->assertNotNull($fired['occurrence_id']);
        $this->assertStringEndsWith('+00:00', $fired['scheduled_for']);
    }

    public function test_includes_unscheduled_anchored_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'Anchored to brushing teeth',
            'series_started_at' => null,
            'recurrence' => null,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(TodayActionsTool::class);

        $response->assertOk();

        $payload = $this->payload($response);
        $anchored = collect($payload)->firstWhere('title', 'Anchored to brushing teeth');

        $this->assertNotNull($anchored);
        $this->assertNull($anchored['scheduled_for']);
    }

    public function test_excludes_tomorrows_actions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        Action::factory()->for($this->loopFor($user))->create([
            'title' => 'Tomorrow only',
            'series_started_at' => now()->addDay()->startOfDay()->addHours(9),
            'recurrence' => null,
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
            'series_started_at' => now()->subHour(),
            'recurrence' => null,
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
            'series_started_at' => now()->subHour(),
            'recurrence' => null,
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
            'series_started_at' => '2026-07-06 14:00:00', // 23:00 JST — today
            'recurrence' => null,
        ]);
        Action::factory()->for($loop)->create([
            'title' => 'Already tomorrow in Tokyo',
            'series_started_at' => '2026-07-06 16:00:00', // 01:00 JST July 7 — tomorrow
            'recurrence' => null,
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
            'series_started_at' => now()->subHour(),
            'recurrence' => null,
        ]);

        PatYourSelfServer::actingAs($user)
            ->tool(TodayActionsTool::class)
            ->assertOk()
            ->assertSee('No timezone set');
    }

    public function test_it_labels_a_cue_anchored_action_as_anchored(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => null,
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after brushing my teeth'],
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(TodayActionsTool::class);

        $response->assertOk();
        $payload = $this->payload($response);
        $this->assertSame('anchored', $payload[0]['due']);
        $this->assertNull($payload[0]['scheduled_for']);
    }

    public function test_it_does_not_list_yesterdays_missed_occasion(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Action::factory()->for($loop)->create([
            'series_started_at' => Carbon::parse('2026-08-23 09:00:00'),
            'recurrence' => 'daily',
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(TodayActionsTool::class);

        $response->assertOk();
        $payload = $this->payload($response);
        $this->assertCount(1, $payload);
        $this->assertStringStartsWith('2026-08-24', $payload[0]['scheduled_for']);
    }
}
