<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\PendingOutcomesTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The read that opens a check-in. It materialises first, so an occasion the
 * user never logged appears here without anything having written a row on
 * their behalf.
 */
class PendingOutcomesToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
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

    private function action(User $user, string $loopStatus = Intention::STATUS_ACTIVE): Action
    {
        // Anchored in the future so nothing materialises unless a test wants it.
        $anchor = now()->addWeek()->setTime(19, 0);

        return Action::factory()
            ->for(Intention::factory()->for($user)->state(['status' => $loopStatus]))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => $anchor,
                'status' => Action::STATUS_ACTIVE,
            ]);
    }

    /**
     * @return array<int, int>
     */
    private function occurrenceIds(TestResponse $response): array
    {
        return array_column($this->payload($response)['occurrences'], 'occurrence_id');
    }

    public function test_it_lists_unlogged_past_occasions_newest_first(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $older = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(3)]);
        $newer = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDay()]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame(['since', 'count', 'truncated', 'occurrences'], array_keys($payload));
        $this->assertSame([$newer->id, $older->id], array_column($payload['occurrences'], 'occurrence_id'));
        $this->assertSame(2, $payload['count']);
        $this->assertFalse($payload['truncated']);
        $this->assertSame([
            'occurrence_id', 'loop_id', 'loop_title', 'action_id', 'action_title', 'scheduled_for',
        ], array_keys($payload['occurrences'][0]));
    }

    public function test_it_materialises_first_so_a_never_logged_action_still_appears(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subDays(3)->setTime(19, 0);

        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => $anchor,
                'status' => Action::STATUS_ACTIVE,
            ]);

        $this->assertSame(0, Occurrence::count());

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $response->assertOk();
        $this->assertSame(4, $this->payload($response)['count']);
    }

    public function test_it_omits_an_occasion_that_already_has_an_outcome(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $logged = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(2)]);
        ActionLog::factory()->create([
            'action_id' => $action->id,
            'occurrence_id' => $logged->id,
            'user_id' => $user->id,
        ]);
        $open = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDay()]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $this->assertSame([$open->id], $this->occurrenceIds($response));
    }

    public function test_it_omits_occasions_that_have_not_happened_yet(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->addDay()]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $this->assertSame([], $this->occurrenceIds($response));
    }

    public function test_it_defaults_to_the_last_fourteen_days(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $recent = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(3)]);
        Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(30)]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $this->assertSame([$recent->id], $this->occurrenceIds($response));
    }

    public function test_an_older_since_reaches_further_back_because_nothing_expires(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $recent = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(3)]);
        $ancient = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(30)]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, [
            'since' => now()->subDays(60)->toIso8601String(),
        ]);

        $this->assertSame([$recent->id, $ancient->id], $this->occurrenceIds($response));
    }

    public function test_it_omits_paused_loops(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user, Intention::STATUS_PAUSED);

        Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDay()]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $this->assertSame([], $this->occurrenceIds($response));
    }

    public function test_it_omits_archived_loops(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user, Intention::STATUS_ARCHIVED);

        Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDay()]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $this->assertSame([], $this->occurrenceIds($response));
    }

    public function test_it_omits_another_users_occasions(): void
    {
        $theirs = $this->action(User::factory()->create(['timezone' => 'UTC']));
        Occurrence::factory()->create(['action_id' => $theirs->id, 'scheduled_for' => now()->subDay()]);

        $response = PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(PendingOutcomesTool::class, []);

        $this->assertSame([], $this->occurrenceIds($response));
    }

    public function test_it_names_the_loop_and_action_each_occasion_belongs_to(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);
        $action->intention->update(['title' => 'Eating to 80%']);
        $action->update(['title' => 'Put the pan back on the stove']);

        $occurrence = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDay()]);

        $response = PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []);

        $entry = $this->payload($response)['occurrences'][0];

        $this->assertSame($occurrence->id, $entry['occurrence_id']);
        $this->assertSame('Eating to 80%', $entry['loop_title']);
        $this->assertSame('Put the pan back on the stove', $entry['action_title']);
        $this->assertSame($action->intention_id, $entry['loop_id']);
    }

    public function test_it_reports_when_the_list_was_capped(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        for ($i = 1; $i <= 101; $i++) {
            Occurrence::factory()->create([
                'action_id' => $action->id,
                'scheduled_for' => now()->subDays(13)->addMinutes($i),
            ]);
        }

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(PendingOutcomesTool::class, []),
        );

        $this->assertSame(100, $payload['count']);
        $this->assertCount(100, $payload['occurrences']);
        $this->assertTrue($payload['truncated']);
    }
}
