<?php

namespace App\Models;

use Database\Factories\StrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned intervention on an intention. History is never rewritten in
 * place: each shift creates a new version that supersedes the previous one,
 * recording why (stacked on success / restrategized on a user-stated failure)
 * and where in the behavioural chain it intervenes.
 */
#[Fillable([
    'intention_id',
    'version',
    'status',
    'intervention_point',
    'approach',
    'rationale',
    'parent_strategy_id',
    'change_reason',
    'superseded_reason',
    'metadata',
])]
class Strategy extends Model
{
    /** @use HasFactory<StrategyFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_RETIRED = 'retired';

    public const POINT_CUE = 'cue';

    public const POINT_CRAVING = 'craving';

    public const POINT_RESPONSE = 'response';

    public const POINT_REWARD = 'reward';

    public const REASON_INITIAL = 'initial';

    public const REASON_STACKED_ON_SUCCESS = 'stacked_on_success';

    public const REASON_RESTRATEGIZED_ON_FAILURE = 'restrategized_on_failure';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Intention, $this> */
    public function intention(): BelongsTo
    {
        return $this->belongsTo(Intention::class);
    }

    /** @return HasMany<Action, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    /**
     * The earlier version this one derived from / superseded.
     *
     * @return BelongsTo<Strategy, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Strategy::class, 'parent_strategy_id');
    }

    /**
     * Versions derived from this one.
     *
     * @return HasMany<Strategy, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Strategy::class, 'parent_strategy_id');
    }
}
