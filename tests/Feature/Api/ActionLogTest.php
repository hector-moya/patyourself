<?php

namespace Tests\Feature\Api;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * JSON action-logging over the token-authenticated API. Shares its write path
 * (LogAction) and ownership rules with the web side.
 */
class ActionLogTest extends TestCase
{
    use RefreshDatabase;

    private function action(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    public function test_guests_are_unauthorized(): void
    {
        $action = $this->action(User::factory()->create());

        $this->postJson("/api/actions/{$action->id}/logs", [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ])->assertUnauthorized();
    }

    public function test_logs_a_completion(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/actions/{$action->id}/logs", [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ])
            ->assertCreated()
            ->assertJsonPath('data.outcome', ActionLog::OUTCOME_COMPLETED);

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_failure_requires_a_reason(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/actions/{$action->id}/logs", [
            'outcome' => ActionLog::OUTCOME_FAILED,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_logs_a_failure_with_its_reason(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/actions/{$action->id}/logs", [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Friends came over unexpectedly',
        ])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'Friends came over unexpectedly');

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Friends came over unexpectedly',
        ]);
    }

    public function test_rejects_an_unknown_outcome(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/actions/{$action->id}/logs", [
            'outcome' => 'exploded',
        ])->assertUnprocessable()->assertJsonValidationErrors('outcome');
    }

    public function test_forbids_logging_another_users_action(): void
    {
        $action = $this->action(User::factory()->create());
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/actions/{$action->id}/logs", [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ])->assertForbidden();

        $this->assertDatabaseMissing('action_logs', ['action_id' => $action->id]);
    }
}
