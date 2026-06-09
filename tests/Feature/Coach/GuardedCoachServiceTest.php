<?php

namespace Tests\Feature\Coach;

use App\Models\CoachUsage;
use App\Models\User;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\CoachResponse;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\FakeCoachService;
use App\Services\Coach\GuardedCoachService;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The decorator that meters and caps every LLM call: it checks the user's
 * budget before delegating and records token usage after.
 */
class GuardedCoachServiceTest extends TestCase
{
    use RefreshDatabase;

    private function guarded(FakeCoachService $inner, int $budget): GuardedCoachService
    {
        return new GuardedCoachService(
            $inner,
            new CoachUsageGuard(dailyTokenBudget: $budget),
            $this->app->make(AuthFactory::class),
        );
    }

    private function request(): CoachRequest
    {
        return new CoachRequest(
            messages: [Message::user('hi')],
            metadata: ['purpose' => 'chat'],
        );
    }

    public function test_records_usage_after_a_call(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $inner = (new FakeCoachService)->push(new CoachResponse(
            content: '{}',
            model: 'claude-sonnet-4-6',
            promptTokens: 80,
            completionTokens: 20,
        ));

        $this->guarded($inner, 200000)->chat($this->request());

        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'purpose' => 'chat',
            'total_tokens' => 100,
        ]);
    }

    public function test_rejects_a_call_when_the_user_is_over_budget(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // Pre-spend the whole budget.
        (new CoachUsageGuard(100))->record($user, new CoachResponse(
            content: '{}', model: 'fake', promptTokens: 100, completionTokens: 0,
        ), 'chat');

        $inner = new FakeCoachService;

        $this->expectException(CoachQuotaException::class);

        try {
            $this->guarded($inner, 100)->chat($this->request());
        } finally {
            // The provider is never hit once a user is out of budget.
            $this->assertSame([], $inner->requests);
        }
    }

    public function test_delegates_without_metering_when_no_user_is_authenticated(): void
    {
        $inner = (new FakeCoachService)->push('ok');

        $response = $this->guarded($inner, 100)->chat($this->request());

        $this->assertSame('ok', $response->content);
        $this->assertSame(0, CoachUsage::query()->count());
    }

    public function test_exposes_the_inner_driver_name(): void
    {
        $this->assertSame('fake', $this->guarded(new FakeCoachService, 100)->name());
    }
}
