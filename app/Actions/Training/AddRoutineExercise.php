<?php

namespace App\Actions\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use Illuminate\Support\Facades\DB;

/**
 * Appends one exercise to an action's routine — the gym workflow's config
 * extension site. The only place a routine gains a row.
 *
 * Position is computed here, not accepted from the caller: it is always the
 * next open slot, so the unique index on (action_id, position) can never be
 * the reason a legitimate add fails.
 *
 * "Next open slot" is `max(position) + 1`, read with a locking read inside a
 * transaction — the same shape {@see RecordSet} uses for `set_number`, and for
 * the same two reasons. Two quick taps on Add would otherwise both read the
 * routine's current state, both compute the same next position and race each
 * other into that unique index, which surfaces as a 500 on the second one.
 * And a count is only the next open slot while the positions happen to be
 * contiguous: it makes this action's correctness depend on
 * {@see RemoveRoutineExercise} renumbering after every removal, so a routine
 * with a gap in it — from any future writer that does not renumber — would
 * collide on a position that is already taken. The max cannot.
 */
final readonly class AddRoutineExercise
{
    public function handle(Action $action, int $exerciseId, int $targetSets, int $targetReps): ActionExercise
    {
        return DB::transaction(function () use ($action, $exerciseId, $targetSets, $targetReps): ActionExercise {
            $lastPosition = (int) ActionExercise::query()
                ->where('action_id', $action->id)
                ->lockForUpdate()
                ->max('position');

            return ActionExercise::create([
                'action_id' => $action->id,
                'exercise_id' => $exerciseId,
                'position' => $lastPosition + 1,
                'target_sets' => $targetSets,
                'target_reps' => $targetReps,
            ]);
        });
    }
}
