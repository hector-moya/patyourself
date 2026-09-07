<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\StoreRoutineExerciseRequest;
use App\Models\Action;
use App\Models\ActionExercise;
use App\Policies\ActionPolicy;
use App\Services\Training\SessionScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The gym workflow's config extension site, edited: what a routine
 * contains, in what order. {@see SessionScreen} is
 * the read side this writes for.
 *
 * Every write here is gated the same way the rest of the action layer is —
 * {@see ActionPolicy::update} — because a routine is part of
 * the standing prescription, not a fact about any one occasion.
 */
class RoutineController extends Controller
{
    /**
     * Appends one exercise to the routine. Position is assigned here, not
     * accepted from the request: it is always the next open slot, so the
     * unique index on (action_id, position) can never be the reason this
     * fails.
     */
    public function store(StoreRoutineExerciseRequest $request, Action $action): RedirectResponse
    {
        Gate::authorize('update', $action);

        ActionExercise::create([
            'action_id' => $action->id,
            'exercise_id' => $request->validated('exercise_id'),
            'position' => ActionExercise::where('action_id', $action->id)->count() + 1,
            'target_sets' => $request->validated('target_sets'),
            'target_reps' => $request->validated('target_reps'),
        ]);

        return back();
    }

    /**
     * Replaces the routine's order wholesale. `order` must name exactly the
     * action's current rows, once each — anything short of that is refused
     * rather than partially applied, because a silent partial reorder would
     * leave positions the caller cannot predict.
     *
     * Positions are bumped out of the way before being reassigned: writing
     * final positions directly, in the target order, collides with the
     * unique index the moment a row moves later in the list than a row
     * still waiting its turn.
     */
    public function reorder(Request $request, Action $action): RedirectResponse
    {
        Gate::authorize('update', $action);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $current = ActionExercise::where('action_id', $action->id)->pluck('id')->sort()->values()->all();
        $given = collect($validated['order'])->sort()->values()->all();

        if ($current !== $given) {
            throw ValidationException::withMessages([
                'order' => "The order must name exactly this routine's current exercises, once each.",
            ]);
        }

        DB::transaction(function () use ($action, $validated): void {
            ActionExercise::where('action_id', $action->id)->update(['position' => DB::raw('position + 1000000')]);

            foreach ($validated['order'] as $index => $id) {
                ActionExercise::whereKey($id)->update(['position' => $index + 1]);
            }
        });

        return back();
    }

    /**
     * Drops one exercise from the routine. Restrict, not cascade, is what
     * keeps the Exercise and every PerformedSet recorded against it intact —
     * see the action_exercises migration for why the schema itself already
     * refuses to let this cascade. What this adds is closing the gap it
     * leaves: the remaining rows are renumbered so the routine stays
     * contiguous, which is what lets `store` keep computing the next
     * position as a plain count.
     */
    public function destroy(Action $action, ActionExercise $actionExercise): RedirectResponse
    {
        Gate::authorize('update', $action);

        abort_unless($actionExercise->action_id === $action->id, 404);

        DB::transaction(function () use ($action, $actionExercise): void {
            $actionExercise->delete();

            ActionExercise::where('action_id', $action->id)
                ->orderBy('position')
                ->get()
                ->values()
                ->each(fn (ActionExercise $row, int $index) => $row->update(['position' => $index + 1]));
        });

        return back();
    }
}
