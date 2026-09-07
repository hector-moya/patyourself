<?php

namespace App\Actions\Training;

use App\Models\Action;
use App\Models\ActionExercise;

/**
 * Appends one exercise to an action's routine — the gym workflow's config
 * extension site. The only place a routine gains a row.
 *
 * Position is computed here, not accepted from the caller: it is always the
 * next open slot, so the unique index on (action_id, position) can never be
 * the reason a legitimate add fails.
 */
final readonly class AddRoutineExercise
{
    public function handle(Action $action, int $exerciseId, int $targetSets, int $targetReps): ActionExercise
    {
        return ActionExercise::create([
            'action_id' => $action->id,
            'exercise_id' => $exerciseId,
            'position' => ActionExercise::where('action_id', $action->id)->count() + 1,
            'target_sets' => $targetSets,
            'target_reps' => $targetReps,
        ]);
    }
}
