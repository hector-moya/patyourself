<?php

namespace App\Models;

use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A concrete action a strategy prescribes — the source of a rendered action
 * card. Bound to the strategy version that produced it, so superseding a
 * strategy never mutates past actions.
 */
#[Fillable([
    'intention_id',
    'strategy_id',
    'title',
    'description',
    'scheduled_for',
    'recurrence',
    'status',
    'metadata',
])]
class Action extends Model
{
    /** @use HasFactory<ActionFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ARCHIVED = 'archived';

    /** Statuses that mean an action card is still awaiting a log. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE];

    /** Every status an action can hold. */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_ARCHIVED,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** Still awaiting a log — i.e. surfaced as a live action card. */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /**
     * Action cards still awaiting a log — the ones a screen surfaces today.
     *
     * @param  Builder<Action>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** @return BelongsTo<Intention, $this> */
    public function intention(): BelongsTo
    {
        return $this->belongsTo(Intention::class);
    }

    /** @return BelongsTo<Strategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(Strategy::class);
    }

    /** @return HasMany<ActionLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }
}
