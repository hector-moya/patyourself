<?php

namespace Tests\Feature\Notifications;

use App\Events\OccurrenceFired;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use App\Services\Scheduling\TriggerEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The fire -> cue delivery path: an OccurrenceFired event notifies the
 * occasion's owner, and the engine end-to-end persists exactly one database
 * notification.
 */
class SendDueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function dueOccurrence(User $user): Occurrence
    {
        $intention = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->initial()->for($intention)->create();

        $action = Action::factory()->for($intention)->create([
            'strategy_id' => $strategy->id,
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subMinute(),
            'recurrence' => null,
        ]);

        return Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subMinute(),
        ]);
    }

    public function test_occurrence_fired_notifies_the_owner(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $occurrence = $this->dueOccurrence($user);

        event(new OccurrenceFired($occurrence));

        Notification::assertSentTo($user, ActionDueNotification::class);
    }

    public function test_occurrence_fired_does_not_notify_other_users(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $occurrence = $this->dueOccurrence($owner);

        event(new OccurrenceFired($occurrence));

        Notification::assertNotSentTo($other, ActionDueNotification::class);
    }

    public function test_firing_the_engine_persists_one_notification_for_the_owner(): void
    {
        $user = User::factory()->create();
        $occurrence = $this->dueOccurrence($user);

        app(TriggerEngine::class)->fireDueOccurrences();

        $user->refresh();
        $this->assertCount(1, $user->notifications);
        $this->assertSame($occurrence->id, $user->notifications->first()->data['occurrence_id']);
    }

    public function test_re_running_the_engine_does_not_double_notify(): void
    {
        $user = User::factory()->create();
        $this->dueOccurrence($user);

        $engine = app(TriggerEngine::class);
        $engine->fireDueOccurrences();
        $engine->fireDueOccurrences();

        $this->assertCount(1, $user->fresh()->notifications);
    }
}
