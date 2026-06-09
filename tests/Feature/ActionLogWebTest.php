<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Inertia web side of action logging — the endpoint the action cards post
 * to. Writes go through the same shared LogAction as the JSON API and respect
 * ownership.
 */
class ActionLogWebTest extends TestCase
{
    use RefreshDatabase;

    private function action(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    public function test_guests_cannot_log(): void
    {
        $action = $this->action(User::factory()->create());

        $this->post("/actions/{$action->id}/logs", [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ])->assertRedirect('/login');
    }

    public function test_logs_a_completion_and_redirects(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        $this->actingAs($user)
            ->post("/actions/{$action->id}/logs", [
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('action_logs', [
            'action_id' => $action->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
    }

    public function test_failure_without_a_reason_is_rejected(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        $this->actingAs($user)
            ->from('/dashboard')
            ->post("/actions/{$action->id}/logs", [
                'outcome' => ActionLog::OUTCOME_FAILED,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('reason');
    }

    public function test_forbids_logging_another_users_action(): void
    {
        $action = $this->action(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->post("/actions/{$action->id}/logs", [
                'outcome' => ActionLog::OUTCOME_COMPLETED,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('action_logs', ['action_id' => $action->id]);
    }
}
