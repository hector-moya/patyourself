<?php

namespace Tests\Feature\Settings;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TimezoneScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_shows_the_stored_timezone(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);

        $this->actingAs($user)->get(route('timezone.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/timezone')
                ->where('timezone', 'Australia/Brisbane')
                ->has('timezones'));
    }

    public function test_changing_the_timezone_re_anchors_future_occurrences(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(2),
        ]);
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDay()]);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Europe/London'])
            ->assertRedirect();

        $this->assertSame('Europe/London', $user->refresh()->timezone);
        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_an_unknown_identifier_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);

        $this->actingAs($user)
            ->patch(route('timezone.update'), ['timezone' => 'Mars/Olympus_Mons'])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Australia/Brisbane', $user->refresh()->timezone);
    }
}
