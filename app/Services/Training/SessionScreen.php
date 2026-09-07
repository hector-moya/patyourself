<?php

namespace App\Services\Training;

use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use Illuminate\Support\Collection;

/**
 * The recording screen's read model: an occasion's routine, in position
 * order, each exercise carrying the sets already recorded against this
 * occasion.
 *
 * An action with no routine — no workflow, or a workflow action nobody has
 * built a routine for yet — returns empty rather than erroring. The tracker
 * is additive: an occasion for a plain action must still log exactly as it
 * does today.
 *
 * Costs a constant number of queries whatever the routine's length: one for
 * the routine rows, one to eager-load their exercises, and one for every set
 * already recorded against the occasion — never one query per exercise. The
 * exercise is loaded in full, not column-limited: a `->with('exercise:id,name')`
 * would return null for `instructions` forever, with the suite still green —
 * the trap this project has been bitten by three times already.
 */
class SessionScreen
{
    /**
     * @return array<int, array{
     *     exercise: Exercise,
     *     target_sets: int,
     *     target_reps: int,
     *     performed_sets: Collection<int, PerformedSet>,
     * }>
     */
    public function for(Occurrence $occurrence): array
    {
        $routine = ActionExercise::query()
            ->where('action_id', $occurrence->action_id)
            ->with('exercise')
            ->orderBy('position')
            ->get();

        if ($routine->isEmpty()) {
            return [];
        }

        $performedSets = PerformedSet::query()
            ->where('occurrence_id', $occurrence->id)
            ->orderBy('set_number')
            ->get()
            ->groupBy('exercise_id');

        return $routine
            ->map(fn (ActionExercise $row): array => [
                'exercise' => $row->exercise,
                'target_sets' => $row->target_sets,
                'target_reps' => $row->target_reps,
                'performed_sets' => $performedSets->get($row->exercise_id, new Collection)->values(),
            ])
            ->all();
    }
}
