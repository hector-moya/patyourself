<?php

namespace App\Http\Controllers;

use App\Actions\ArchiveAction;
use App\Actions\CreateAction;
use App\Actions\RescheduleAction;
use App\Http\Requests\RescheduleActionRequest;
use App\Http\Requests\StoreActionRequest;
use App\Models\Action;
use App\Models\Intention;
use App\Services\Authoring\AuthoredAction;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

    /**
     * Adds an action to the loop's current experiment.
     *
     * Without this the action layer was frozen between experiments: splitting
     * one action into two meant starting an experiment the user did not want.
     */
    public function store(StoreActionRequest $request, Intention $intention, CreateAction $createAction): RedirectResponse
    {
        Gate::authorize('update', $intention);

        $kind = $request->string('kind')->toString();

        try {
            $createAction->handle($intention, new AuthoredAction(
                title: $request->string('title')->toString(),
                description: null,
                kind: $kind,
                time: $kind === 'clock' ? $request->input('time') : null,
                recurrence: $kind === 'clock' ? $request->input('recurrence', 'once') : null,
                anchor: $kind === 'anchored' ? $request->input('anchor') : null,
            ));
        } catch (StrategyTransitionException $e) {
            throw ValidationException::withMessages(['title' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Retires an action.
     *
     * DELETE is the verb for "retire this", but the write is an archive:
     * occurrences hang off an action and outcomes hang off occurrences, so a
     * real delete would cascade away the evidence this app exists to keep.
     */
    public function destroy(Action $action, ArchiveAction $archiveAction): RedirectResponse
    {
        Gate::authorize('update', $action);

        $archiveAction->handle($action);

        return back();
    }
}
