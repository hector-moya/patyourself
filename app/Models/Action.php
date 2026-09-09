<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ActionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * Every status an action can hold. A standing prescription is live or it is
     * put away; whether any given occasion of it was answered is a fact about
     * the occasion, not about the prescription.
     */
    public const STATUSES = [
        self::STATUS_ACTIVE,
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
     * The routine attached at the gym workflow's config extension site: what
     * this action's occasions are meant to contain, one row per exercise, in
     * `position` order.
     *
     * Empty for every action with no workflow, which is the ordinary case —
     * a plain action has no routine and reads exactly as it always has.
     *
     * @return HasMany<ActionExercise, $this>
     */
    public function actionExercises(): HasMany
    {
        return $this->hasMany(ActionExercise::class)->orderBy('position');
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
     * The occasions still ahead of now and still awaiting an outcome, soonest
     * first. Exists so a screen listing several actions can load all of their
     * next occasions in one query instead of one per action — see
     * {@see self::nextOccurrenceAt()}.
     *
     * @return HasMany<Occurrence, $this>
     */
    public function upcomingOccurrences(): HasMany
    {
        return $this->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>=', Date::now())
            ->orderBy('scheduled_for');
    }

    /**
     * The next occasion still awaiting an outcome, at or after now. Null when
     * there is none — including for a cue-anchored action, which has no grid,
     * and for a day whose slots are all behind us: the grid is materialised
     * only through the end of the local day, so there is genuinely nothing
     * further to report.
     *
     * Honours an eager load when the caller arranged one. A screen listing
     * several actions loads `upcomingOccurrences` once for all of them; a
     * caller holding a single action calls `upcomingOccurrences()` fresh
     * instead — building the same query without loading or caching the
     * relation — which is the cheaper of the two for one row. Routing both
     * branches through the same relation method, rather than restating its
     * clauses here, keeps them structurally unable to disagree.
     */
    public function nextOccurrenceAt(): ?CarbonImmutable
    {
        if ($this->relationLoaded('upcomingOccurrences')) {
            return $this->upcomingOccurrences->first()?->scheduled_for;
        }

        return $this->upcomingOccurrences()->value('scheduled_for');
    }
}
