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

    /** Every habit-loop direction (build a good habit / break a bad one). */
    public const TYPES = [self::TYPE_BUILD, self::TYPE_BREAK];

    /** Every lifecycle status a loop can hold. */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAUSED,
        self::STATUS_ARCHIVED,
        self::STATUS_COMPLETED,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function isBuild(): bool
    {
        return $this->type === self::TYPE_BUILD;
    }

    public function isBreaking(): bool
    {
        return $this->type === self::TYPE_BREAK;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The next monotonic version number for this loop's strategy history —
     * versioning never reuses or rewrites a number.
     */
    public function nextStrategyVersion(): int
    {
        return (int) $this->strategies()->max('version') + 1;
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
}
