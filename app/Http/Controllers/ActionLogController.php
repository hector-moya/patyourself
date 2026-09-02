<?php

namespace App\Http\Controllers;

use App\Actions\LogAction;
use App\Http\Requests\LogActionRequest;
use App\Models\Action;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The Inertia web side of action logging — the endpoint the action cards post
 * to. Records through the same shared {@see LogAction} as the JSON API and
 * gates on ownership.
 */
class ActionLogController extends Controller
{
    public function store(LogActionRequest $request, Action $action, LogAction $log): RedirectResponse
    {
        Gate::authorize('log', $action);

        $entry = $log->handle($request->user(), $action, $request->validated());

        // Blob's corner reaction, carried as a one-request flash so the reward
        // arrives with the act rather than the next time the dashboard opens.
        // The id, not a flag: two outcomes in a row each deserve a reaction,
        // and a flag that is already set never changes.
        return back()->with('logged_outcome_id', $entry->id);
    }
}
