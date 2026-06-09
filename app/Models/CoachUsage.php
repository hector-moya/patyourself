<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded server-side LLM call — the cost-guard ledger. Rows are written
 * after every coach call and summed over a rolling window to enforce a user's
 * daily token budget.
 */
#[Fillable([
    'user_id',
    'model',
    'purpose',
    'prompt_tokens',
    'completion_tokens',
    'total_tokens',
])]
class CoachUsage extends Model
{
    /**
     * Usage recorded on or after the given moment — the window the cost guard
     * sums when checking a user's budget.
     *
     * @param  Builder<CoachUsage>  $query
     */
    #[Scope]
    protected function since(Builder $query, CarbonInterface $moment): void
    {
        $query->where('created_at', '>=', $moment);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
