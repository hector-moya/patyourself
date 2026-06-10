<?php

namespace Tests\Feature\Ai;

use App\Ai\Middleware\GuardCoachUsage;
use App\Models\User;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class GuardCoachUsageTest extends TestCase
{
    use RefreshDatabase;

    private function respond(int $prompt = 80, int $completion = 20): callable
    {
        // Stub $next: returns a real AgentResponse carrying usage.
        return fn ($p) => new AgentResponse(
            invocationId: 'inv_1',
            text: 'ok',
            usage: new Usage(promptTokens: $prompt, completionTokens: $completion),
            meta: new Meta(model: 'claude-sonnet-4-6'),
        );
    }

    private function middleware(int $budget): GuardCoachUsage
    {
        config()->set('services.coach.daily_token_budget', $budget);

        return $this->app->make(GuardCoachUsage::class);
    }

    private function prompt(): object
    {
        // The middleware only reads the agent's class name for `purpose`.
        return new class
        {
            public object $agent;

            public function __construct()
            {
                $this->agent = new class {};
            }
        };
    }

    public function test_records_usage_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->middleware(200000)->handle($this->prompt(), $this->respond());

        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'total_tokens' => 100,
        ]);
    }

    public function test_rejects_an_over_budget_user_before_calling_next(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        (new CoachUsageGuard(100))->record($user, new CoachResponse(
            content: '{}', model: 'fake', promptTokens: 100, completionTokens: 0,
        ), 'chat');

        $called = false;

        $this->expectException(CoachQuotaException::class);

        try {
            $this->middleware(100)->handle($this->prompt(), function () use (&$called) {
                $called = true;
            });
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_passes_through_unmetered_with_no_authenticated_user(): void
    {
        $response = $this->middleware(100)->handle($this->prompt(), $this->respond());

        $this->assertSame('ok', $response->text);
        $this->assertDatabaseCount('coach_usages', 0);
    }
}
