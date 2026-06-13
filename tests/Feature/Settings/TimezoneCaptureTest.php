<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_stores_a_valid_timezone(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->actingAs($user)
            ->patch('/settings/timezone', ['timezone' => 'America/New_York'])
            ->assertRedirect();

        $this->assertSame('America/New_York', $user->fresh()->timezone);
    }

    public function test_rejects_an_invalid_timezone(): void
    {
        $user = User::factory()->create(['timezone' => null]);

        $this->actingAs($user)
            ->patch('/settings/timezone', ['timezone' => 'Mars/Phobos'])
            ->assertSessionHasErrors('timezone');

        $this->assertNull($user->fresh()->timezone);
    }
}
