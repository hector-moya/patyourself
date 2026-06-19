<?php

namespace Tests\Feature\Actions;

use App\Actions\LogAction;
use App\Events\ActionLogged;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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
        // A one-off (no recurrence): completing or skipping it closes it out,
        // which is what the existing close-behaviour tests assert. Recurring
        // re-arm is covered by the dedicated tests below.
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => null,
                'scheduled_for' => null,
            ]);
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function recurringAction(User $user, array $overrides = []): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create(array_merge([
                'status' => Action::STATUS_ACTIVE,
                'recurrence' => 'daily',
                'scheduled_for' => now()->subMinutes(5),
            ], $overrides));
    }

    public function test_completing_a_recurring_action_rearms_it_to_the_next_occurrence(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
        // The completion is preserved as a log event.
        $this->assertSame(1, $fresh->logs()->where('outcome', ActionLog::OUTCOME_COMPLETED)->count());
    }

    public function test_skipping_a_recurring_action_rearms_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_SKIPPED]);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture());
    }

    public function test_failing_a_recurring_action_leaves_it_open(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'busy',
        ]);

        $this->assertSame(Action::STATUS_ACTIVE, $action->fresh()->status); // unchanged, no re-arm
    }

    public function test_completing_a_one_off_action_closes_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user, ['recurrence' => null]);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_completing_an_anchored_action_closes_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user, [
            'recurrence' => null,
            'scheduled_for' => null,
            'metadata' => ['schedule_kind' => 'anchored', 'anchor' => 'after coffee'],
        ]);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertSame(Action::STATUS_COMPLETED, $action->fresh()->status);
    }

    public function test_completing_a_stale_recurring_action_fast_forwards_to_the_future(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->recurringAction($user, ['scheduled_for' => now()->subDays(3)]);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $fresh = $action->fresh();
        $this->assertSame(Action::STATUS_PENDING, $fresh->status);
        $this->assertTrue($fresh->scheduled_for->isFuture()); // not a past slot
    }

    public function test_logging_marks_the_actions_unread_notification_read(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);   // one-off; mark-read applies to any shape
        $user->notify(new ActionDueNotification($action));
        $this->assertCount(1, $user->unreadNotifications);

        app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_logging_a_failure_also_marks_the_cue_read(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);
        $user->notify(new ActionDueNotification($action));

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Ran out of time',
        ]);

        $this->assertCount(0, $user->fresh()->unreadNotifications);
    }

    public function test_logging_leaves_other_actions_notifications_unread(): void
    {
        $user = User::factory()->create();
        $logged = $this->action($user);
        $other = $this->action($user);
        $user->notify(new ActionDueNotification($logged));
        $user->notify(new ActionDueNotification($other));

        app(LogAction::class)->handle($user, $logged, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertCount(1, $user->fresh()->unreadNotifications);
    }

    public function test_logging_does_not_touch_another_users_notifications(): void
    {
        $owner = User::factory()->create();
        $action = $this->action($owner);
        $other = User::factory()->create();
        $other->notify(new ActionDueNotification($action));

        app(LogAction::class)->handle($owner, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertCount(1, $other->fresh()->unreadNotifications);
    }

    public function test_logging_without_a_notification_still_succeeds(): void
    {
        $user = User::factory()->create();
        $action = $this->action($user);

        $log = app(LogAction::class)->handle($user, $action, ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $log->outcome);
    }

    public function test_logging_dispatches_the_action_logged_event(): void
    {
        Event::fake([ActionLogged::class]);

        $user = User::factory()->create();
        $action = $this->action($user);

        $log = app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Too tired',
        ]);

        Event::assertDispatched(ActionLogged::class, function (ActionLogged $event) use ($user, $action, $log): bool {
            return $event->user->is($user)
                && $event->action->is($action)
                && $event->log->is($log);
        });
    }

    public function test_logging_dispatches_the_event_for_every_outcome(): void
    {
        Event::fake([ActionLogged::class]);

        $user = User::factory()->create();

        foreach ([ActionLog::OUTCOME_COMPLETED, ActionLog::OUTCOME_SKIPPED] as $outcome) {
            app(LogAction::class)->handle($user, $this->action($user), ['outcome' => $outcome]);
        }

        Event::assertDispatchedTimes(ActionLogged::class, 2);
    }
}
