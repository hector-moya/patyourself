<?php

namespace Tests\Feature\Notifications;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActionDueNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function action(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user)->create(['title' => 'Read before bed']))
            ->create(['title' => 'Read ten pages']);
    }

    private function occurrenceFor(Action $action): Occurrence
    {
        return Occurrence::factory()->for($action)->create();
    }

    private function firedOccurrence(): Occurrence
    {
        $intention = Intention::factory()
            ->for(User::factory())
            ->create(['title' => 'Meditate daily', 'status' => Intention::STATUS_ACTIVE]);

        $action = Action::factory()->for($intention)->create([
            'status' => Action::STATUS_ACTIVE,
        ]);

        return Occurrence::factory()->for($action)->create([
            'fired_at' => Carbon::parse('2026-06-15T07:00:00+00:00'),
        ]);
    }

    public function test_it_uses_only_the_database_channel(): void
    {
        $notification = new ActionDueNotification($this->firedOccurrence());

        $this->assertSame(['database'], $notification->via(new User));
    }

    public function test_the_database_channel_stays_synchronous(): void
    {
        $notification = new ActionDueNotification($this->firedOccurrence());

        $this->assertSame('sync', $notification->viaConnections()['database']);
    }

    public function test_to_array_carries_the_inbox_payload(): void
    {
        $occurrence = $this->firedOccurrence();

        $payload = (new ActionDueNotification($occurrence))->toArray(new User);

        $this->assertSame([
            'occurrence_id' => $occurrence->id,
            'action_id' => $occurrence->action_id,
            'intention_id' => $occurrence->action->intention_id,
            'title' => 'Meditate daily',
            'fired_at' => '2026-06-15T07:00:00+00:00',
        ], $payload);
    }

    public function test_emails_the_cue_when_the_user_wants_every_cue(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $channels = (new ActionDueNotification($this->occurrenceFor($this->action($user))))->via($user);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_does_not_email_the_cue_for_digest_users(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->assertNotContains(
            'mail',
            (new ActionDueNotification($this->occurrenceFor($this->action($user))))->via($user),
        );
    }

    public function test_does_not_email_the_cue_when_reminders_are_off(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_OFF]);

        $this->assertNotContains(
            'mail',
            (new ActionDueNotification($this->occurrenceFor($this->action($user))))->via($user),
        );
    }

    public function test_the_in_app_cue_is_delivered_in_every_mode(): void
    {
        foreach (User::EMAIL_REMINDER_MODES as $mode) {
            $user = User::factory()->create(['email_reminders' => $mode]);

            $this->assertContains(
                'database',
                (new ActionDueNotification($this->occurrenceFor($this->action($user))))->via($user),
                "database channel missing for mode [{$mode}]",
            );
        }
    }

    public function test_the_cue_email_names_the_action_and_links_to_the_app(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);
        $action = $this->action($user);

        $mail = (new ActionDueNotification($this->occurrenceFor($action)))->toMail($user)->render();

        $this->assertStringContainsString('Read ten pages', $mail);
        $this->assertStringContainsString('Read before bed', $mail);
        $this->assertStringContainsString(route('loops.show', $action->intention_id), $mail);
    }
}
