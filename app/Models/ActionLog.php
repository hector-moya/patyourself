<?php

namespace App\Models;

use Database\Factories\ActionLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completion / failure / skip event. On failure the user-stated reason is
 * captured here — that reason is what drives a strategy to restrategize.
 */
#[Fillable([
    'action_id',
    'user_id',
    'outcome',
    'reason',
    'logged_at',
    'metadata',
])]
class ActionLog extends Model
{
    /** @use HasFactory<ActionLogFactory> */
    use HasFactory;

    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_SKIPPED = 'skipped';

    /** Every outcome a log event can record. */
    public const OUTCOMES = [self::OUTCOME_COMPLETED, self::OUTCOME_FAILED, self::OUTCOME_SKIPPED];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** A win for the streak — the action was completed. */
    public function isWin(): bool
    {
        return $this->outcome === self::OUTCOME_COMPLETED;
    }

    /** A failure that should carry a user-stated reason. */
    public function isFailure(): bool
    {
        return $this->outcome === self::OUTCOME_FAILED;
    }

    public function isSkip(): bool
    {
        return $this->outcome === self::OUTCOME_SKIPPED;
    }

    /**
     * Only the failure events — the user-stated reasons that drive a strategy
     * to restrategize.
     *
     * @param  Builder<ActionLog>  $query
     */
    #[Scope]
    protected function failures(Builder $query): void
    {
        $query->where('outcome', self::OUTCOME_FAILED);
    }

    /** @return BelongsTo<Action, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
