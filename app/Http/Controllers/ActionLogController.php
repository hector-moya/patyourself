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

        $log->handle($request->user(), $action, $request->validated());

        return back();
    }
}
