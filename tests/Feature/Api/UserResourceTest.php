<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The API speaks one consistent JSON shape: every payload — including the
 * authenticated user — flows through a resource. This guards the user shape the
 * Phase 2 mobile client consumes, and that no sensitive fields leak.
 */
class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_the_user_resource_shape(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'created_at']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_me_never_leaks_sensitive_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $payload = $this->getJson('/api/me')->json('user');

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertArrayNotHasKey('remember_token', $payload);
        $this->assertArrayNotHasKey('two_factor_secret', $payload);
    }

    public function test_token_issuance_returns_the_same_user_shape(): void
    {
        User::factory()->create([
            'email' => 'coach@example.com',
            'password' => Hash::make('correct-horse'),
        ]);

        $this->postJson('/api/auth/token', [
            'email' => 'coach@example.com',
            'password' => 'correct-horse',
            'device_name' => 'iphone',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'created_at']]);
    }
}
