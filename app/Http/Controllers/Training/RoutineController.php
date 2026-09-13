<?php

namespace App\Http\Controllers\Training;

use App\Actions\Training\AddRoutineExercise;
use App\Actions\Training\RemoveRoutineExercise;
use App\Actions\Training\ReorderRoutine;
use App\Actions\Training\UpdateRoutineExercise;
use App\Http\Controllers\Controller;
use App\Http\Requests\Training\ReorderRoutineRequest;
use App\Http\Requests\Training\StoreRoutineExerciseRequest;
use App\Http\Requests\Training\UpdateRoutineExerciseRequest;
use App\Models\Action;
use App\Models\ActionExercise;
use App\Policies\ActionPolicy;
use App\Services\Training\RoutineOrderException;
use App\Services\Training\SessionScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The gym workflow's config extension site, edited: what a routine
 * contains, in what order. {@see SessionScreen} is the read side this
 * writes for.
 *
 * Every write here is gated the same way the rest of the action layer is —
 * {@see ActionPolicy::update} — because a routine is part of the standing
 * prescription, not a fact about any one occasion. The writes themselves
 * live in `App\Actions\Training`; this only authorizes, validates the
 * request's shape, and delegates.
 */
class RoutineController extends Controller
{
    public function store(StoreRoutineExerciseRequest $request, Action $action, AddRoutineExercise $add): RedirectResponse
    {
        Gate::authorize('update', $action);

        $add->handle(
            $action,
            $request->integer('exercise_id'),
            $request->integer('target_sets'),
            $request->integer('target_reps'),
        );

        return back();
    }

    public function reorder(ReorderRoutineRequest $request, Action $action, ReorderRoutine $reorder): RedirectResponse
    {
        Gate::authorize('update', $action);

        try {
            $reorder->handle($action, $request->validated('order'));
        } catch (RoutineOrderException $e) {
            // Realistically a stale client (the routine changed since the page
            // loaded), not a malformed request — so it belongs on the form
            // rather than in a 500.
            throw ValidationException::withMessages(['order' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Changes what one row of the routine targets.
     *
     * The `action_id` check is the same one {@see self::destroy()} makes and
     * for the same reason: route model binding resolves the row from the whole
     * table, so without it, owning one action would be enough to edit another
     * action's routine. A 404 rather than a 403 — a row that is not on this
     * action does not exist as far as this URL is concerned.
     */
    public function update(
        UpdateRoutineExerciseRequest $request,
        Action $action,
        ActionExercise $actionExercise,
        UpdateRoutineExercise $update,
    ): RedirectResponse {
        Gate::authorize('update', $action);

        abort_unless($actionExercise->action_id === $action->id, 404);

        $update->handle(
            $actionExercise,
            $request->integer('target_sets'),
            $request->integer('target_reps'),
        );

        return back();
    }

    /**
     * Drops one exercise from the routine. The URL names both an action and
     * a row, and Gate::authorize only checks the action — abort_unless is
     * what stops a user from using an action they own to delete a row that
     * actually belongs to a different action.
     */
    public function destroy(Action $action, ActionExercise $actionExercise, RemoveRoutineExercise $remove): RedirectResponse
    {
        Gate::authorize('update', $action);

        abort_unless($actionExercise->action_id === $action->id, 404);

        $remove->handle($action, $actionExercise);

        return back();
    }
}
