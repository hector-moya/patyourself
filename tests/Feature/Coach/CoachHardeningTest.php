<?php

namespace Tests\Feature\Coach;

use App\Ai\Agents\Coach;
use App\Models\User;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The chat endpoint's hardening: per-user token budget (429), graceful coach
 * failure (503 not 500), per-minute rate limiting (429), and usage metering.
 *
 * Token metering is now performed by GuardCoachUsage middleware on the Coach
 * agent. The middleware records usage from AgentResponse::$usage; under fakes
 * the SDK returns Usage(0, 0), so we assert the row is created (not the count).
 *
 * The old CoachService/FakeCoachService binding is no longer used; these tests
 * fake the Coach SDK agent directly.
 */
class CoachHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_turn_meters_token_usage(): void
    {
        $user = User::factory()->create();
        Coach::fake(['Hi there.']);

        $this->actingAs($user)
            ->postJson('/chat', ['message' => 'hello'])
            ->assertOk();

        // GuardCoachUsage middleware records a row for every turn. Under a fake
        // the SDK returns Usage(0,0), so we only assert the row exists.
        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'purpose' => 'coach',
        ]);
    }

    public function test_an_over_budget_user_is_rejected_with_429(): void
    {
        $user = User::factory()->create();
        Coach::fake(['should not run']);

        // Pre-spend the budget entirely.
        (new CoachUsageGuard(100))->record($user, new CoachResponse(
            content: '{}', model: 'fake', promptTokens: 100, completionTokens: 0,
        ), 'chat');

        config()->set('services.coach.daily_token_budget', 100);

        $this->actingAs($user)
            ->postJson('/chat', ['message' => 'hello'])
            ->assertStatus(429);
    }

    public function test_a_coach_failure_degrades_to_503_not_500(): void
    {
        $user = User::factory()->create();

        // A closure fake that throws is treated as a CoachException, which the
        // exception renderer in bootstrap/app.php converts to 503.
        Coach::fake(function (): never {
            throw new CoachException('provider down');
        });

        $this->actingAs($user)
            ->postJson('/chat', ['message' => 'hello'])
            ->assertStatus(503)
            ->assertJsonStructure(['message']);
    }

    public function test_chat_is_rate_limited_per_minute(): void
    {
        config()->set('services.coach.rate_per_minute', 1);

        $user = User::factory()->create();
        Coach::fake(['first', 'second']);

        $this->actingAs($user);
        $this->postJson('/chat', ['message' => 'one'])->assertOk();
        $this->postJson('/chat', ['message' => 'two'])->assertStatus(429);
    }
}
