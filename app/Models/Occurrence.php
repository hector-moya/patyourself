<?php

namespace App\Models;

use Database\Factories\OccurrenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One instance of an action — the occasion an outcome attaches to. An
 * occurrence with no outcome and a scheduled time in the past is the unlogged
 * set a check-in asks about. Nothing expires it: it stays loggable forever.
 */
#[Fillable([
    'action_id',
    'scheduled_for',
    'fired_at',
])]
class Occurrence extends Model
{
    /** @use HasFactory<OccurrenceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'fired_at' => 'immutable_datetime',
        ];
    }

    /** Whether this occasion already carries its outcome. */
    public function isLogged(): bool
    {
        return $this->log()->exists();
    }

    /**
     * Occasions still awaiting an outcome — the catch-up set.
     *
     * @param  Builder<Occurrence>  $query
     */
    #[Scope]
    protected function unlogged(Builder $query): void
    {
        $query->whereDoesntHave('log');
    }

    /**
     * Occasions whose cue has not been delivered. `fired_at` is the trigger
     * engine's idempotency guard: a null here is the only thing that lets an
     * occasion fire, and stamping it is what makes a repeated or overlapping
     * run a no-op.
     *
     * @param  Builder<Occurrence>  $query
     */
    #[Scope]
    protected function unfired(Builder $query): void
    {
        $query->whereNull('fired_at');
    }

    /** @return BelongsTo<Action, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    /**
     * The one outcome recorded for this occasion, if it has been logged.
     *
     * @return HasOne<ActionLog, $this>
     */
    public function log(): HasOne
    {
        return $this->hasOne(ActionLog::class);
    }
}
