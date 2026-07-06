<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\GetLoopTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class GetLoopToolTest extends TestCase
{
    use RefreshDatabase;

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

        $response = PatYourSelfServer::actingAs($user)
            ->tool(GetLoopTool::class, ['intention_id' => $loop->id]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame([
            'id', 'title', 'description', 'type', 'status', 'loop', 'active_strategy_version', 'strategies',
        ], array_keys($payload));

        $this->assertSame('Read before bed', $payload['title']);
        $this->assertSame([
            'cue', 'craving', 'response', 'reward',
        ], array_keys($payload['loop']));
        $this->assertSame('After brushing teeth', $payload['loop']['cue']);

        $this->assertSame(2, $payload['active_strategy_version']);

        $this->assertSame([1, 2], array_column($payload['strategies'], 'version'));

        foreach ($payload['strategies'] as $strategy) {
            $this->assertSame([
                'version', 'status', 'intervention_point', 'approach', 'rationale', 'change_reason', 'superseded_reason',
            ], array_keys($strategy));
        }

        $this->assertSame('Book on the pillow', $payload['strategies'][0]['approach']);
        $this->assertSame('Phone charges outside the bedroom', $payload['strategies'][1]['approach']);
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
