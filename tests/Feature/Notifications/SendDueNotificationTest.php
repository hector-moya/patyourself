<?php

namespace Tests\Feature\Notifications;

use App\Events\ActionFired;
use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The fire -> cue delivery path: an ActionFired event notifies the action's
 * owner, and the engine end-to-end persists exactly one database notification.
 */
class SendDueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function dueAction(User $user): Action
    {
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        return Action::factory()->for($intention)->create([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => now()->subMinute(),
            'recurrence' => null,
        ]);
    }

    public function test_action_fired_notifies_the_owner(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $action = $this->dueAction($user);

        event(new ActionFired($action));

        Notification::assertSentTo($user, ActionDueNotification::class);
    }

    public function test_action_fired_does_not_notify_other_users(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $action = $this->dueAction($owner);

        event(new ActionFired($action));

        Notification::assertNotSentTo($other, ActionDueNotification::class);
    }

    public function test_firing_the_engine_persists_one_notification_for_the_owner(): void
    {
        $user = User::factory()->create();
        $action = $this->dueAction($user);

        app(TriggerEngine::class)->fireDueActions();

        $user->refresh();
        $this->assertCount(1, $user->notifications);
        $this->assertSame($action->id, $user->notifications->first()->data['action_id']);
    }

    public function test_re_running_the_engine_does_not_double_notify(): void
    {
        $user = User::factory()->create();
        $this->dueAction($user);

        $engine = app(TriggerEngine::class);
        $engine->fireDueActions();
        $engine->fireDueActions();

        $this->assertCount(1, $user->fresh()->notifications);
    }
}
