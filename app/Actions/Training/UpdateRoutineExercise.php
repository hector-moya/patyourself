<?php

namespace App\Actions\Training;

use App\Models\ActionExercise;
use App\Models\PerformedSet;

/**
 * Changes what one routine row targets — the gym workflow's config extension
 * site, amended. The only place a row's targets change.
 *
 * `position` is deliberately untouched. Before this existed the only way to
 * change a target was {@see RemoveRoutineExercise} followed by
 * {@see AddRoutineExercise}, and adding appends, so correcting a typo sent the
 * exercise to the end of the routine. Keeping the place is the reason this
 * writer exists at all.
 *
 * Nothing here reads or writes a {@see PerformedSet}. Targets are
 * the standing prescription and sets are what happened on one occasion; a
 * routine that said three while four were recorded is two true facts, and
 * amending the first must not rewrite the second.
 *
 * No transaction and no locking read, unlike its sibling writers: this changes
 * two columns on one row it was handed, computing nothing from the rest of the
 * routine, so there is no sequence for two concurrent taps to race over.
 */
final readonly class UpdateRoutineExercise
{
    public function handle(ActionExercise $actionExercise, int $targetSets, int $targetReps): ActionExercise
    {
        $actionExercise->update([
            'target_sets' => $targetSets,
            'target_reps' => $targetReps,
        ]);

        return $actionExercise->refresh();
    }
}
