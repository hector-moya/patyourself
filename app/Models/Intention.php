<?php

namespace App\Models;

use Database\Factories\IntentionFactory;
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
 * A habit "loop": the structured Intention the LLM authors, modelled as the
 * cue -> craving -> response -> reward chain. The UI only renders it.
 */
#[Fillable([
    'user_id',
    'title',
    'description',
    'type',
    'status',
    'cue',
    'craving',
    'response',
    'reward',
    'metadata',
])]
class Intention extends Model
{
    /** @use HasFactory<IntentionFactory> */
    use HasFactory;

    public const TYPE_BUILD = 'build';

    public const TYPE_BREAK = 'break';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_COMPLETED = 'completed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Only the loops a user is currently working — excludes paused, archived
     * and completed ones.
     *
     * @param  Builder<Intention>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Strategy, $this> */
    public function strategies(): HasMany
    {
        return $this->hasMany(Strategy::class);
    }

    /** @return HasMany<Action, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(Action::class);
    }

    /** @return HasMany<Summary, $this> */
    public function summaries(): HasMany
    {
        return $this->hasMany(Summary::class);
    }

    /**
     * Every completion / failure / skip event across this loop's actions — the
     * structured archive the rolling summary is built from.
     *
     * @return HasManyThrough<ActionLog, Action, $this>
     */
    public function actionLogs(): HasManyThrough
    {
        return $this->hasManyThrough(ActionLog::class, Action::class);
    }

    /**
     * The most recent rolling summary for this loop (if any).
     *
     * @return HasOne<Summary, $this>
     */
    public function latestSummary(): HasOne
    {
        return $this->hasOne(Summary::class)
            ->where('scope', Summary::SCOPE_INTENTION)
            ->latestOfMany();
    }

    /**
     * The single currently-active strategy version (if any).
     *
     * @return HasOne<Strategy, $this>
     */
    public function activeStrategy(): HasOne
    {
        return $this->hasOne(Strategy::class)
            ->where('status', Strategy::STATUS_ACTIVE)
            ->latestOfMany('version');
    }

    /**
     * The one action a card can log right now — the most recent action still
     * awaiting an outcome (pending or active). Completed / skipped actions are
     * closed out, so they never surface as the loggable one.
     *
     * @return HasOne<Action, $this>
     */
    public function activeAction(): HasOne
    {
        return $this->hasOne(Action::class)
            ->whereIn('status', [Action::STATUS_PENDING, Action::STATUS_ACTIVE])
            ->latestOfMany();
    }
}
