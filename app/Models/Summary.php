<?php

namespace App\Models;

use Database\Factories\SummaryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rolling-summary snapshot powering pattern detection without ML. Action-log
 * events are periodically folded into a running text summary, scoped either to
 * one intention or to the whole account.
 */
#[Fillable([
    'user_id',
    'intention_id',
    'scope',
    'content',
    'window_start',
    'window_end',
    'events_count',
    'metadata',
])]
class Summary extends Model
{
    /** @use HasFactory<SummaryFactory> */
    use HasFactory;

    public const SCOPE_INTENTION = 'intention';

    public const SCOPE_USER = 'user';

    /** Every scope a rolling summary can cover. */
    public const SCOPES = [self::SCOPE_INTENTION, self::SCOPE_USER];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'events_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** Covers a single loop (and is therefore tied to an intention). */
    public function isIntentionScope(): bool
    {
        return $this->scope === self::SCOPE_INTENTION;
    }

    /** Spans the whole account, across every loop. */
    public function isUserScope(): bool
    {
        return $this->scope === self::SCOPE_USER;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Intention, $this> */
    public function intention(): BelongsTo
    {
        return $this->belongsTo(Intention::class);
    }
}
