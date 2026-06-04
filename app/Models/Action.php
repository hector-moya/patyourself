<?php

namespace App\Models;

use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'metadata' => 'array',
        ];
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
