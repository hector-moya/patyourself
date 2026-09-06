<?php

namespace App\Models;

use Database\Factories\StrategyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
    'review_at',
    'verdict',
    'verdict_note',
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

    public const VERDICT_WORKED = 'worked';

    public const VERDICT_FAILED = 'failed';

    public const VERDICT_INCONCLUSIVE = 'inconclusive';

    /** Every verdict an experiment can end with. */
    public const VERDICTS = [self::VERDICT_WORKED, self::VERDICT_FAILED, self::VERDICT_INCONCLUSIVE];

    /** Every status a strategy version can hold. */
    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_SUPERSEDED, self::STATUS_RETIRED];

    /**
     * The behavioural chain, upstream → downstream. The order matters: a
     * restrategy shifts the intervention point along this sequence.
     */
    public const INTERVENTION_POINTS = [
        self::POINT_CUE,
        self::POINT_CRAVING,
        self::POINT_RESPONSE,
        self::POINT_REWARD,
    ];

    /** Every reason a new version gets created. */
    public const CHANGE_REASONS = [
        self::REASON_INITIAL,
        self::REASON_STACKED_ON_SUCCESS,
        self::REASON_RESTRATEGIZED_ON_FAILURE,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'metadata' => 'array',
            'review_at' => 'immutable_datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * A version is concluded once it carries a verdict. Concluding does not
     * supersede it — a strategy that worked keeps running.
     */
    public function isConcluded(): bool
    {
        return $this->verdict !== null;
    }

    /**
     * Running, has a `review_at`, and it has passed. A superseded or retired
     * version never reports under review — even with a past `review_at` and no
     * verdict — because starting the next experiment is how the ordinary flow
     * moves on without a formal conclusion; without this check, that version
     * would read as "under review" forever. Open-ended experiments (no
     * `review_at`) are never under review either. Both are what keep the
     * notebook from nagging.
     */
    public function isUnderReview(): bool
    {
        return $this->isActive()
            && ! $this->isConcluded()
            && $this->review_at !== null
            && $this->review_at->isPast();
    }

    /**
     * Whole days this version ran, counting from 0.
     *
     * Counts to now while the version is still the running one, and stops at
     * the moment its successor began once it has been superseded. The ladder on
     * the loop record renders every version, so an uncapped count made a
     * version replaced months ago report a day number that was still climbing —
     * history that keeps moving, which is the one thing this notebook does not
     * do.
     *
     * The end is taken from the successor's own `created_at` rather than from
     * this row's `updated_at`. `StartExperiment` supersedes and creates the next
     * version in one transaction, so the successor's start *is* the moment this
     * one stopped, and it cannot drift — whereas any later edit to a superseded
     * row would move `updated_at`. That is the same defect already recorded
     * against dating concluded experiments, and it is not worth importing here.
     *
     * A concluded version is deliberately not capped. Concluding does not
     * supersede — see {@see ConcludeExperiment} — so a version concluded as
     * `worked` is still running, and freezing its count would stop the clock on
     * the experiment the loop screen is currently showing.
     */
    public function dayOfExperiment(): int
    {
        $ranUntil = $this->successor?->created_at ?? now();

        return (int) $this->created_at->startOfDay()->diffInDays($ranUntil->startOfDay());
    }

    /** The planned run length in whole days, or null when open-ended. */
    public function plannedDays(): ?int
    {
        return $this->review_at === null
            ? null
            : (int) $this->created_at->startOfDay()->diffInDays($this->review_at->startOfDay());
    }

    /**
     * Where this version sits in the cue → craving → response → reward chain
     * (0 = cue ... 3 = reward), or null if the point is unrecognised. Lets
     * callers reason about shifting the intervention upstream or downstream.
     */
    public function interventionPointIndex(): ?int
    {
        $index = array_search($this->intervention_point, self::INTERVENTION_POINTS, true);

        return $index === false ? null : $index;
    }

    /**
     * The live version(s) — those not yet superseded or retired.
     *
     * @param  Builder<Strategy>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Oldest version first — the order a strategy's history reads in.
     *
     * @param  Builder<Strategy>  $query
     */
    #[Scope]
    protected function orderedByVersion(Builder $query): void
    {
        $query->orderBy('version');
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
     * Every outcome recorded while this version was the one running. Logs
     * attribute to a version through actions.strategy_id, so the attribution is
     * structural rather than inferred from dates.
     *
     * @return HasManyThrough<ActionLog, Action, $this>
     */
    public function actionLogs(): HasManyThrough
    {
        return $this->hasManyThrough(ActionLog::class, Action::class);
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

    /**
     * The version that replaced this one, if any.
     *
     * Its `created_at` is when this version stopped running: `StartExperiment`
     * supersedes the current version and creates its successor inside one
     * transaction. Only ever one in practice — superseding requires an active
     * version and there is only one of those at a time — but it is ordered by
     * version so the relation stays single-valued by construction rather than
     * by assumption.
     *
     * @return HasOne<Strategy, $this>
     */
    public function successor(): HasOne
    {
        return $this->hasOne(Strategy::class, 'parent_strategy_id')->oldestOfMany('version');
    }
}
