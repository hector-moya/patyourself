<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailReminderPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_default_to_a_seven_am_digest(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::EMAIL_REMINDERS_DIGEST, $user->email_reminders);
        $this->assertSame('07:00', $user->digest_time);
        $this->assertNull($user->digest_last_sent_on);
    }

    public function test_the_preference_columns_are_writable(): void
    {
        $user = User::factory()->create();

        $user->update([
            'email_reminders' => User::EMAIL_REMINDERS_EVERY_CUE,
            'digest_time' => '21:30',
        ]);

        $this->assertSame(User::EMAIL_REMINDERS_EVERY_CUE, $user->fresh()->email_reminders);
        $this->assertSame('21:30', $user->fresh()->digest_time);
    }

    public function test_digest_last_sent_on_casts_to_a_date(): void
    {
        $user = User::factory()->create(['digest_last_sent_on' => '2026-08-24']);

        $this->assertSame('2026-08-24', $user->fresh()->digest_last_sent_on->toDateString());
    }

    public function test_the_mode_list_holds_exactly_the_three_modes(): void
    {
        $this->assertSame(
            ['off', 'digest', 'every_cue'],
            User::EMAIL_REMINDER_MODES,
        );
    }
}
