<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LogActionOutcomeTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class LogActionOutcomeToolTest extends TestCase
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

    private function oneOffAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => null,
                'scheduled_for' => null,
            ]);
    }

    public function test_logs_a_completion_and_closes_the_one_off_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame([
            'log_id', 'outcome', 'reason', 'logged_at', 'action_status',
        ], array_keys($payload));
        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $payload['outcome']);
        $this->assertNull($payload['reason']);
        $this->assertSame(Action::STATUS_COMPLETED, $payload['action_status']);
        $this->assertIsInt($payload['log_id']);
        $this->assertIsString($payload['logged_at']);
        $this->assertNotSame('', $payload['logged_at']);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_logs_a_skip_and_closes_the_one_off_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_SKIPPED,
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_SKIPPED,
        ]);
        $this->assertSame(Action::STATUS_SKIPPED, $action->fresh()->status);
    }

    public function test_rejects_a_wholly_unknown_action_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => 999999,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);
    }

    public function test_a_failure_requires_a_reason(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
            ])
            ->assertHasErrors();

        $this->assertDatabaseMissing('action_logs', ['action_id' => $action->id]);
    }

    public function test_logs_a_failure_with_its_reason(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => 'Friends came over unexpectedly',
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Friends came over unexpectedly',
        ]);
    }

    public function test_completing_a_recurring_action_rolls_it_forward(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => 'daily',
                'scheduled_for' => now()->subMinutes(5),
            ]);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertOk();

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
    }

    public function test_cannot_log_another_users_action(): void
    {
        $action = $this->oneOffAction(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertDatabaseMissing('action_logs', ['action_id' => $action->id]);
    }

    public function test_rejects_an_unknown_outcome(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->oneOffAction($user);

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => 'exploded',
            ])
            ->assertHasErrors();
    }
}
