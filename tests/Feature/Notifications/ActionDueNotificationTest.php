<?php

namespace Tests\Feature\Notifications;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_emails_the_cue_when_the_user_wants_every_cue(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);

        $channels = (new ActionDueNotification($this->action($user)))->via($user);

        $this->assertContains('mail', $channels);
        $this->assertContains('database', $channels);
    }

    public function test_does_not_email_the_cue_for_digest_users(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->assertNotContains('mail', (new ActionDueNotification($this->action($user)))->via($user));
    }

    public function test_does_not_email_the_cue_when_reminders_are_off(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_OFF]);

        $this->assertNotContains('mail', (new ActionDueNotification($this->action($user)))->via($user));
    }

    public function test_the_in_app_cue_is_delivered_in_every_mode(): void
    {
        foreach (User::EMAIL_REMINDER_MODES as $mode) {
            $user = User::factory()->create(['email_reminders' => $mode]);

            $this->assertContains(
                'database',
                (new ActionDueNotification($this->action($user)))->via($user),
                "database channel missing for mode [{$mode}]",
            );
        }
    }

    public function test_the_cue_email_names_the_action_and_links_to_the_app(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);
        $action = $this->action($user);

        $mail = (new ActionDueNotification($action))->toMail($user)->render();

        $this->assertStringContainsString('Read ten pages', $mail);
        $this->assertStringContainsString('Read before bed', $mail);
        $this->assertStringContainsString(route('intentions.show', $action->intention_id), $mail);
    }
}
