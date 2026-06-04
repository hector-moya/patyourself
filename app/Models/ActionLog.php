<?php

namespace App\Models;

use Database\Factories\ActionLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
            'metadata' => 'array',
        ];
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
