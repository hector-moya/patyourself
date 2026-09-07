<?php

namespace App\Actions\Training;

use App\Models\Action;
use App\Models\ActionExercise;
use App\Services\Training\RoutineOrderException;
use Illuminate\Support\Facades\DB;

/**
 * Replaces an action's routine order wholesale. The only place a routine's
 * positions are rewritten in bulk — {@see RemoveRoutineExercise} only
 * renumbers around a single removal.
 *
 * `$order` must name exactly the action's current rows, once each —
 * anything short of that is refused rather than partially applied, because
 * a silent partial reorder would leave positions the caller cannot
 * predict. That check depends on which rows the action currently has, which
 * only the database knows, so it lives here rather than in the request's
 * shape validation.
 *
 * Positions are bumped out of the way before being reassigned: writing
 * final positions directly, in the target order, collides with the unique
 * index on (action_id, position) the moment a row moves later in the list
 * than a row still waiting its turn.
 */
final readonly class ReorderRoutine
{
    /**
     * @param  list<int>  $order  The action's ActionExercise ids, in the desired top-to-bottom order.
     *
     * @throws RoutineOrderException when `$order` is not exactly a permutation of the action's current rows.
     */
    public function handle(Action $action, array $order): void
    {
        $current = ActionExercise::where('action_id', $action->id)->pluck('id')->sort()->values()->all();
        $given = collect($order)->sort()->values()->all();

        if ($current !== $given) {
            throw new RoutineOrderException(
                "The order must name exactly this routine's current exercises, once each.",
            );
        }

        DB::transaction(function () use ($action, $order): void {
            ActionExercise::where('action_id', $action->id)->update(['position' => DB::raw('position + 1000000')]);

            foreach ($order as $index => $id) {
                ActionExercise::whereKey($id)->update(['position' => $index + 1]);
            }
        });
    }
}
