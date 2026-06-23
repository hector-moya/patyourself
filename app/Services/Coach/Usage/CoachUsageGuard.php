<?php

namespace App\Services\Coach\Usage;

use App\Models\CoachUsage;
use App\Models\User;
use App\Services\Coach\Exceptions\CoachQuotaException;
use Illuminate\Support\Facades\Date;

/**
 * The cost guard. Records every server-side LLM call's token usage and enforces
 * a rolling 24-hour per-user token budget — the single place spend is metered
 * and capped, so the GuardCoachUsage middleware covers every agent call at once.
 */
final readonly class CoachUsageGuard
{
    public function __construct(private int $dailyTokenBudget) {}

    /**
     * Append a usage row for a completed call.
     */
    public function record(User $user, string $model, int $promptTokens, int $completionTokens, ?string $purpose = null): CoachUsage
    {
        return CoachUsage::create([
            'user_id' => $user->id,
            'model' => $model,
            'purpose' => $purpose,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
        ]);
    }

    /**
     * Total tokens a user has spent in the trailing 24 hours.
     */
    public function tokensUsedToday(User $user): int
    {
        return (int) CoachUsage::query()
            ->where('user_id', $user->id)
            ->since(Date::now()->subDay())
            ->sum('total_tokens');
    }

    /**
     * Whether the user is at or over their budget. A budget of 0 (or less)
     * disables the cap entirely.
     */
    public function exceedsBudget(User $user): bool
    {
        if ($this->dailyTokenBudget <= 0) {
            return false;
        }

        return $this->tokensUsedToday($user) >= $this->dailyTokenBudget;
    }

    /**
     * @throws CoachQuotaException when the user is out of budget.
     */
    public function ensureWithinBudget(User $user): void
    {
        if ($this->exceedsBudget($user)) {
            throw CoachQuotaException::dailyTokenBudget(
                $user,
                $this->dailyTokenBudget,
                $this->tokensUsedToday($user),
            );
        }
    }

    /**
     * Today's usage for the per-user card: total spent, the budget, remaining
     * (null when uncapped), and a per-purpose breakdown of the rolling window.
     *
     * @return array{used:int, budget:int, remaining:?int, breakdown:array<string,int>}
     */
    public function snapshotFor(User $user): array
    {
        $used = $this->tokensUsedToday($user);

        $breakdown = CoachUsage::query()
            ->where('user_id', $user->id)
            ->since(Date::now()->subDay())
            ->selectRaw('purpose, SUM(total_tokens) as tokens')
            ->groupBy('purpose')
            ->pluck('tokens', 'purpose')
            ->mapWithKeys(fn ($tokens, $purpose): array => [(string) ($purpose ?? 'other') => (int) $tokens])
            ->all();

        return [
            'used' => $used,
            'budget' => $this->dailyTokenBudget,
            'remaining' => $this->dailyTokenBudget > 0 ? max(0, $this->dailyTokenBudget - $used) : null,
            'breakdown' => $breakdown,
        ];
    }
}
