<?php

namespace App\Http\Controllers\Training;

use App\Actions\Training\RecordSet;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OccurrenceLogController;
use App\Http\Requests\Training\StorePerformedSetRequest;
use App\Models\Exercise;
use App\Models\Occurrence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Writes one set actually performed during a session — the gym workflow's
 * record extension site, written.
 *
 * Keyed to the occurrence, exactly as `PerformedSet` itself is: sets are
 * ticked off during a session, long before anyone presses Done or Missed.
 * Gated the same way the rest of the recording flow is —
 * `Gate::authorize('log', $occurrence)`, the same ability
 * {@see OccurrenceLogController} checks — because a set is a fact about one
 * occasion, not the standing prescription {@see RoutineController} edits.
 */
class PerformedSetController extends Controller
{
    public function store(StorePerformedSetRequest $request, Occurrence $occurrence, RecordSet $record): RedirectResponse
    {
        Gate::authorize('log', $occurrence);

        $exercise = Exercise::query()->findOrFail($request->integer('exercise_id'));

        $record->handle(
            $occurrence,
            $exercise,
            $request->integer('reps'),
            $request->filled('weight') ? $request->float('weight') : null,
        );

        return back();
    }
}
