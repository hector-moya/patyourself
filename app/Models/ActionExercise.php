<?php

namespace App\Models;

use Database\Factories\ActionExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The gym workflow's config extension site: one row of the routine an action
 * prescribes — which exercise, in what order, and what it targets.
 *
 * `target_reps` is a single integer, not a range: v1 says "10", not "8-12".
 * A range is two columns and a display rule, addable later without touching
 * anything written here.
 */
#[Fillable([
    'action_id',
    'exercise_id',
    'position',
    'target_sets',
    'target_reps',
])]
class ActionExercise extends Model
{
    /** @use HasFactory<ActionExerciseFactory> */
    use HasFactory;

    /** @return BelongsTo<Action, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(Action::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
