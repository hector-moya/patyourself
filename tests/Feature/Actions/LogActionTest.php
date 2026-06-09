<?php

namespace Tests\Feature\Actions;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared write path for recording an action's outcome. A log is an
 * immutable event; on failure it carries the user-stated reason that later
 * feeds the versioned-strategy logic. The action's own status advances to
 * match. The only place the logging flow writes to the database.
 */
class LogActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    public function test_completion_records_a_log_and_completes_the_action(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);

        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $log->outcome);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($action->id, $log->action_id);
        $this->assertNotNull($log->logged_at);
        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_failure_stores_the_user_stated_reason(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Got home too late and skipped it',
        ]);

        $this->assertSame(ActionLog::OUTCOME_FAILED, $log->outcome);
        $this->assertSame('Got home too late and skipped it', $log->reason);
    }

    public function test_failure_leaves_the_action_open_for_a_retry(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Forgot',
        ]);

        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status);
    }

    public function test_skip_marks_the_action_skipped(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_SKIPPED,
        ]);

        $this->assertSame(Action::STATUS_SKIPPED, $action->fresh()->status);
    }
}
