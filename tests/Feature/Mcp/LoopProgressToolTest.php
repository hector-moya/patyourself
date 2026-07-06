<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LoopProgressTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class LoopProgressToolTest extends TestCase
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

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LoopProgressTool::class, ['intention_id' => $loop->id]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame([
            'loop_id', 'title', 'streak', 'completion_rate', 'totals', 'recent', 'last_logged_at',
        ], array_keys($payload));
        $this->assertSame($loop->id, $payload['loop_id']);
        $this->assertSame('Meditate', $payload['title']);
        $this->assertSame(75, $payload['completion_rate']);
        $this->assertSame([
            'completed' => 3,
            'failed' => 1,
            'skipped' => 0,
        ], $payload['totals']);
    }

    public function test_rejects_another_users_loop(): void
    {
        $foreign = Intention::factory()->create();

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class, ['intention_id' => $foreign->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_rejects_a_wholly_unknown_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class, ['intention_id' => 999999])
            ->assertHasErrors(['Not found.']);
    }

    public function test_requires_an_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class)
            ->assertHasErrors();
    }
}
