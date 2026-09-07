<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Begins a recording session: materialises the occasion its sets and eventual
 * verdict hang on, without logging anything.
 *
 * Sets are ticked off during a session, long before anyone presses Done or
 * Missed, and a cue-anchored action has no occasion until something creates
 * one. This is that something, reached the moment recording starts, over
 * HTTP. See {@see MaterialisesOccasion} for why it must never create an
 * ActionLog — that is the whole reason this is a separate seam from
 * {@see ActionLogController}, which does.
 */
class SessionController extends Controller
{
    public function materialise(Action $action, MaterialisesOccasion $materialises): RedirectResponse
    {
        Gate::authorize('log', $action);

        $occurrence = $materialises->forAction($action);

        // Carried as a one-request flash, the same shape ActionLogController
        // and OccurrenceLogController already use to hand an id back to the
        // page that posted: a recording screen reads this to know which
        // occasion its sets belong to.
        return back()->with('occurrence_id', $occurrence->id);
    }
}
