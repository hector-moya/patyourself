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
 * contiguous, which is how the list reads back as 1, 2, 3 rather than as
 * whatever survived.
 *
 * That renumbering is presentation, not a correctness dependency.
 * {@see AddRoutineExercise} takes `max(position) + 1` and would place the next
 * row correctly over a gap this never closed — the two were coupled while the
 * next position was a count, and are deliberately not any more.
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
