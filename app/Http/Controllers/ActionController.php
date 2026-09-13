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
use App\Services\Scheduling\MaterialiseOccurrences;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ActionController extends Controller
{
    /**
     * Amends an action: its wording, its schedule, or both.
     *
     * The two halves go to different writers on purpose. A title is a plain
     * column write; a schedule change re-anchors the series and purges the
     * grid it abandons, so routing a rename through the rescheduler would
     * delete future occasions for a text edit. `kind` is what says a schedule
     * was actually submitted — the same rule `UpdateActionTool` applies, so the
     * app and the connector amend an action the same way.
     */
    public function update(
        RescheduleActionRequest $request,
        Action $action,
        RescheduleAction $reschedule,
        MaterialiseOccurrences $materialise,
    ): RedirectResponse {
        Gate::authorize('update', $action);

        $fields = array_filter(
            [
                'title' => $request->validated('title'),
                'description' => $request->validated('description'),
            ],
            static fn ($value): bool => $value !== null,
        );

        if ($fields !== []) {
            $action->update($fields);
        }

        if ($request->validated('kind') !== null) {
            $action = $reschedule->handle(
                $action,
                $request->validated('kind'),
                $request->validated('time'),
                $request->validated('recurrence'),
                $request->validated('anchor'),
                $request->user()->timezone ?? (string) config('app.timezone'),
            );

            // Mirrors Api\ActionController: a schedule that actually changed
            // has just purged every unlogged slot ahead of now, so without
            // this, `back()` re-renders the loop screen against an empty
            // grid and the cadence line loses its time until FireDueActions
            // runs. Scoped to this branch on purpose — an unchanged schedule
            // (RescheduleAction's guard) purges nothing, so there is nothing
            // to rebuild and this must not run for a pure rename.
            $materialise->forLoop($action->intention);
        }

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
