<?php

namespace Tests\Feature\Coach;

use App\Models\CoachUsage;
use App\Models\User;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\Usage\CoachUsageGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * The cost guard: records each LLM call's token usage and enforces a rolling
 * 24-hour per-user token budget.
 */
class CoachUsageGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_a_usage_row_with_token_counts(): void
    {
        $user = User::factory()->create();
        $guard = new CoachUsageGuard(dailyTokenBudget: 200000);

        $guard->record($user, 'claude-sonnet-4-6', 100, 50, 'chat');

        $this->assertDatabaseHas('coach_usages', [
            'user_id' => $user->id,
            'model' => 'claude-sonnet-4-6',
            'purpose' => 'chat',
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
        ]);
    }

    public function test_tokens_used_today_sums_only_the_rolling_window(): void
    {
        $user = User::factory()->create();
        $guard = new CoachUsageGuard(dailyTokenBudget: 200000);

        $guard->record($user, 'claude-sonnet-4-6', 100, 100, 'chat'); // 200, now

        // An older call outside the 24h window must not count.
        $old = $guard->record($user, 'claude-sonnet-4-6', 500, 500, 'chat');
        $old->forceFill(['created_at' => Date::now()->subDays(2)])->save();

        $this->assertSame(200, $guard->tokensUsedToday($user));
    }

    public function test_exceeds_budget_trips_at_the_cap(): void
    {
        $user = User::factory()->create();
        $guard = new CoachUsageGuard(dailyTokenBudget: 300);

        $guard->record($user, 'claude-sonnet-4-6', 100, 100, 'chat'); // 200 used
        $this->assertFalse($guard->exceedsBudget($user));

        $guard->record($user, 'claude-sonnet-4-6', 100, 0, 'chat'); // 300 used
        $this->assertTrue($guard->exceedsBudget($user));
    }

    public function test_a_zero_budget_disables_the_cap(): void
    {
        $user = User::factory()->create();
        $guard = new CoachUsageGuard(dailyTokenBudget: 0);

        $guard->record($user, 'claude-sonnet-4-6', 10000, 10000, 'chat');

        $this->assertFalse($guard->exceedsBudget($user));
    }

    public function test_ensure_within_budget_throws_when_over(): void
    {
        $user = User::factory()->create();
        $guard = new CoachUsageGuard(dailyTokenBudget: 100);
        $guard->record($user, 'claude-sonnet-4-6', 100, 50, 'chat');

        $this->expectException(CoachQuotaException::class);

        $guard->ensureWithinBudget($user);
    }

    public function test_budgets_are_per_user(): void
    {
        $heavy = User::factory()->create();
        $light = User::factory()->create();
        $guard = new CoachUsageGuard(dailyTokenBudget: 100);

        $guard->record($heavy, 'claude-sonnet-4-6', 100, 100, 'chat');

        $this->assertTrue($guard->exceedsBudget($heavy));
        $this->assertFalse($guard->exceedsBudget($light));
        $this->assertSame(0, CoachUsage::query()->where('user_id', $light->id)->count());
    }
}
