<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LogActionOutcomeTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogActionOutcomeToolTest extends TestCase
{
    use RefreshDatabase;

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

        PatYourSelfServer::actingAs($user)
            ->tool(LogActionOutcomeTool::class, [
                'action_id' => $action->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertOk()
            ->assertSee(ActionLog::OUTCOME_COMPLETED);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
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
