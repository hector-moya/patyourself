<?php

namespace Tests\Feature\Coach;

use App\Models\User;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\FakeCoachService;
use App\Services\Coach\GuardedCoachService;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The chat endpoint's hardening: per-user token budget (429), graceful coach
 * failure (503 not 500), per-minute rate limiting (429), and usage metering.
 */
class CoachHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function bindGuarded(FakeCoachService $inner, int $budget): void
    {
        $this->app->instance(CoachService::class, new GuardedCoachService(
            $inner,
            new CoachUsageGuard($budget),
            $this->app->make(AuthFactory::class),
        ));
    }

    public function test_a_successful_turn_meters_token_usage(): void
    {
        $user = User::factory()->create();
        $inner = (new FakeCoachService)->push(new CoachResponse(
            content: (string) json_encode(['reply' => 'Hi there.']),
            model: 'claude-sonnet-4-6',
            promptTokens: 40,
            completionTokens: 10,
        ));
        $this->bindGuarded($inner, 200000);

        $this->actingAs($user)
            ->postJson('/chat', ['message' => 'hello'])
            ->assertOk();

        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'purpose' => 'chat',
            'total_tokens' => 50,
        ]);
    }

    public function test_an_over_budget_user_is_rejected_with_429(): void
    {
        $user = User::factory()->create();

        // Pre-spend the budget.
        (new CoachUsageGuard(100))->record($user, new CoachResponse(
            content: '{}', model: 'fake', promptTokens: 100, completionTokens: 0,
        ), 'chat');

        $inner = (new FakeCoachService)->pushJson(['reply' => 'should not run']);
        $this->bindGuarded($inner, 100);

        $this->actingAs($user)
            ->postJson('/chat', ['message' => 'hello'])
            ->assertStatus(429);

        // The provider was never hit.
        $this->assertSame([], $inner->requests);
    }

    public function test_a_coach_failure_degrades_to_503_not_500(): void
    {
        $user = User::factory()->create();

        $this->app->instance(CoachService::class, new class implements CoachService
        {
            public function name(): string
            {
                return 'boom';
            }

            public function chat(CoachRequest $request): CoachResponse
            {
                throw new CoachException('provider down');
            }
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
        $inner = (new FakeCoachService)
            ->pushJson(['reply' => 'first'])
            ->pushJson(['reply' => 'second']);
        $this->app->instance(CoachService::class, $inner);

        $this->actingAs($user);
        $this->postJson('/chat', ['message' => 'one'])->assertOk();
        $this->postJson('/chat', ['message' => 'two'])->assertStatus(429);
    }
}
