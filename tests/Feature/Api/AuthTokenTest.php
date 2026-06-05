<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'coach@example.com',
            'password' => Hash::make('correct-horse'),
        ]);
    }

    public function test_valid_credentials_issue_a_token()
    {
        $user = $this->user();

        $response = $this->postJson('/api/auth/token', [
            'email' => 'coach@example.com',
            'password' => 'correct-horse',
            'device_name' => 'iphone',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.email', 'coach@example.com');

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'iphone',
        ]);
    }

    public function test_wrong_password_is_rejected_without_issuing_a_token()
    {
        $this->user();

        $this->postJson('/api/auth/token', [
            'email' => 'coach@example.com',
            'password' => 'wrong',
            'device_name' => 'iphone',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_device_name_is_required()
    {
        $this->user();

        $this->postJson('/api/auth/token', [
            'email' => 'coach@example.com',
            'password' => 'correct-horse',
        ])->assertStatus(422)->assertJsonValidationErrors('device_name');
    }

    public function test_a_token_can_reach_protected_endpoints_then_be_revoked()
    {
        $this->user();

        $token = $this->postJson('/api/auth/token', [
            'email' => 'coach@example.com',
            'password' => 'correct-horse',
            'device_name' => 'iphone',
        ])->json('token');

        $auth = ['Authorization' => "Bearer {$token}"];

        $this->getJson('/api/me', $auth)
            ->assertOk()
            ->assertJsonPath('user.email', 'coach@example.com');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->postJson('/api/auth/logout', [], $auth)->assertOk();

        // Revoking deletes the token server-side, so it can never authenticate again.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_token_endpoint_is_rate_limited()
    {
        $this->user();

        $payload = [
            'email' => 'coach@example.com',
            'password' => 'wrong',
            'device_name' => 'iphone',
        ];

        // throttle:6,1 — the first six are processed (422), the seventh is blocked.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/auth/token', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/token', $payload)->assertStatus(429);
    }
}
