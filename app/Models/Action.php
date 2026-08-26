<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;

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
    'series_started_at',
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
            'series_started_at' => 'immutable_datetime',
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

    /**
     * Every materialised instance of this action. The action row is the
     * standing prescription; these are the occasions it has actually
     * produced, and the rows outcomes attach to.
     *
     * @return HasMany<Occurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(Occurrence::class);
    }

    /**
     * The next occasion still awaiting an outcome, at or after now. Null when
     * there is none — including for a cue-anchored action, which has no grid,
     * and for a day whose slots are all behind us: the grid is materialised
     * only through the end of the local day, so there is genuinely nothing
     * further to report.
     */
    public function nextOccurrenceAt(): ?CarbonImmutable
    {
        return $this->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>=', Date::now())
            ->orderBy('scheduled_for')
            ->value('scheduled_for');
    }
}
