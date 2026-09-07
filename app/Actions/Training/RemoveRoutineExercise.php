<?php

namespace App\Actions\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use Illuminate\Support\Facades\DB;

/**
 * Drops one exercise from an action's routine. Restrict, not cascade, is
 * what keeps the Exercise and every PerformedSet recorded against it
 * intact — see the action_exercises migration for why the schema itself
 * already refuses to let this cascade. What this adds is closing the gap
 * removal leaves: the remaining rows are renumbered so the routine stays
 * contiguous, which is what lets {@see AddRoutineExercise} keep computing
 * the next position as a plain count.
 */
final readonly class RemoveRoutineExercise
{
    public function handle(Action $action, ActionExercise $actionExercise): void
    {
        DB::transaction(function () use ($action, $actionExercise): void {
            $actionExercise->delete();

            ActionExercise::where('action_id', $action->id)
                ->orderBy('position')
                ->get()
                ->values()
                ->each(fn (ActionExercise $row, int $index) => $row->update(['position' => $index + 1]));
        });
    }
}
