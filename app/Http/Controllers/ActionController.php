<?php

namespace App\Http\Controllers;

use App\Actions\RescheduleAction;
use App\Http\Requests\RescheduleActionRequest;
use App\Models\Action;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ActionController extends Controller
{
    public function update(RescheduleActionRequest $request, Action $action, RescheduleAction $reschedule): RedirectResponse
    {
        Gate::authorize('update', $action);

        $reschedule->handle(
            $action,
            $request->validated('kind'),
            $request->validated('time'),
            $request->validated('recurrence'),
            $request->validated('anchor'),
            $request->user()->timezone ?? (string) config('app.timezone'),
        );

        return back();
    }
}
