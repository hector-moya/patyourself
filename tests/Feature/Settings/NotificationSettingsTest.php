<?php

namespace Tests\Feature\Settings;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Notifications\ActionDueNotification;
use App\Notifications\DailyDigestNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings/notifications page ships in the next task; the render
        // test only needs Inertia's component + props, not a built Vite manifest.
        $this->withoutVite();
    }

    public function test_the_page_renders_for_a_signed_in_user(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('notifications.edit'))
            ->assertOk();
    }

    public function test_guests_cannot_reach_it(): void
    {
        $this->get(route('notifications.edit'))->assertRedirect(route('login'));
    }

    public function test_a_user_can_switch_to_every_cue(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->actingAs($user)
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE,
            ])
            ->assertRedirect();

        $this->assertSame(User::EMAIL_REMINDERS_EVERY_CUE, $user->fresh()->email_reminders);
    }

    public function test_a_user_can_set_their_digest_time(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
                'digest_time' => '21:30',
            ])
            ->assertRedirect();

        $this->assertSame('21:30', $user->fresh()->digest_time);
    }

    public function test_the_digest_time_is_required_for_the_digest_mode(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
            ])
            ->assertSessionHasErrors('digest_time');
    }

    public function test_a_malformed_digest_time_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_DIGEST,
                'digest_time' => 'half seven',
            ])
            ->assertSessionHasErrors('digest_time');
    }

    public function test_an_unknown_mode_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), ['email_reminders' => 'carrier pigeon'])
            ->assertSessionHasErrors('email_reminders');
    }

    public function test_both_reminder_emails_link_to_these_settings(): void
    {
        $user = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE]);
        $action = Action::factory()
            ->for(Intention::factory()->for($user))
            ->create();

        $cue = (new ActionDueNotification($action))->toMail($user)->render();
        $digest = (new DailyDigestNotification(collect([$action])))->toMail($user)->render();

        $this->assertStringContainsString(route('notifications.edit'), $cue);
        $this->assertStringContainsString(route('notifications.edit'), $digest);
    }

    public function test_one_user_cannot_change_anothers_preferences(): void
    {
        $owner = User::factory()->create(['email_reminders' => User::EMAIL_REMINDERS_DIGEST]);

        $this->actingAs(User::factory()->create())
            ->patch(route('notifications.update'), [
                'email_reminders' => User::EMAIL_REMINDERS_OFF,
            ]);

        $this->assertSame(User::EMAIL_REMINDERS_DIGEST, $owner->fresh()->email_reminders);
    }
}
