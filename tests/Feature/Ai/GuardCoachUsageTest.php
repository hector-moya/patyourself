<?php

namespace Tests\Feature\Ai;

use App\Ai\Middleware\GuardCoachUsage;
use App\Models\User;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Mockery;
use Tests\TestCase;

class GuardCoachUsageTest extends TestCase
{
    use RefreshDatabase;

    private function makeProvider(): TextProvider
    {
        return Mockery::mock(TextProvider::class);
    }

    private function agentPrompt(Agent $agent): AgentPrompt
    {
        return new AgentPrompt(
            agent: $agent,
            prompt: 'hello',
            attachments: [],
            provider: $this->makeProvider(),
            model: 'claude-sonnet-4-6',
        );
    }

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

    public function test_records_usage_for_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $prompt = $this->agentPrompt(new StubCoach);

        $result = $this->middleware(200000)->handle($prompt, $this->respond());

        $this->assertSame('ok', $result->text);

        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'purpose' => 'stubcoach',
            'model' => 'claude-sonnet-4-6',
            'prompt_tokens' => 80,
            'completion_tokens' => 20,
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
            $this->middleware(100)->handle($this->agentPrompt(new StubCoach), function () use (&$called) {
                $called = true;
            });
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_passes_through_unmetered_with_no_authenticated_user(): void
    {
        // StubPassthrough has no conversationParticipant method, so falls through unmetered.
        $result = $this->middleware(100)->handle($this->agentPrompt(new StubPassthrough), $this->respond());

        $this->assertSame('ok', $result->text);
        $this->assertDatabaseCount('coach_usages', 0);
    }
}

// ---------------------------------------------------------------------------
// Inline stub agents used by the tests above
// ---------------------------------------------------------------------------

final class StubCoach implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

/** A plain agent with no conversationParticipant — simulates unauthenticated passthrough. */
final class StubPassthrough implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}
