<?php

namespace App\Http\Controllers;

use App\Actions\LogAction;
use App\Http\Requests\LogActionRequest;
use App\Models\Occurrence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Logs an outcome against one specific occasion.
 *
 * Distinct from {@see ActionLogController}, which logs the *live* slot an
 * action card is showing. Catching up means recording Tuesday's occasion on
 * Friday, and the action-keyed endpoint would attach that to Friday instead.
 */
class OccurrenceLogController extends Controller
{
    public function store(LogActionRequest $request, Occurrence $occurrence, LogAction $log): RedirectResponse
    {
        Gate::authorize('log', $occurrence);

        if ($occurrence->isLogged()) {
            return back()->withErrors(['outcome' => 'That occasion already has an outcome.']);
        }

        $entry = $log->handle($request->user(), $occurrence->action, $request->validated(), $occurrence);

        // See ActionLogController: the corner reaction rides the session for
        // exactly one request.
        return back()->with('logged_outcome_id', $entry->id);
    }
}
