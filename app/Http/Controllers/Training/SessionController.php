<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\ActionLogController;
use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Occurrence;
use App\Services\Training\SessionScreen;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Begins a recording session, and shows it.
 *
 * Sets are ticked off during a session, long before anyone presses Done or
 * Missed, and a cue-anchored action has no occasion until something creates
 * one. `materialise` is that something, reached the moment recording starts,
 * over HTTP. See {@see MaterialisesOccasion} for why it must never create an
 * ActionLog — that is the whole reason this is a separate seam from
 * {@see ActionLogController}, which does.
 */
class SessionController extends Controller
{
    public function materialise(Action $action, MaterialisesOccasion $materialises): RedirectResponse
    {
        Gate::authorize('log', $action);

        $occurrence = $materialises->forAction($action);

        // Redirects straight into the session this occasion now belongs to,
        // not back to wherever recording began — there is somewhere for the
        // user to land now that `show` exists. The flash survives alongside
        // it in the same shape ActionLogController and OccurrenceLogController
        // already use to hand an id back to the page that posted, for anything
        // else still reading it that way.
        return redirect()
            ->route('training.session.show', $occurrence)
            ->with('occurrence_id', $occurrence->id);
    }

    /**
     * The session screen: one occasion's routine, its target sets/reps, and
     * how many of each are already recorded — plus whatever it takes to draw
     * the plain verdict controls every occasion already has.
     *
     * An action with no routine renders with an empty `exercises` list rather
     * than erroring; see {@see SessionScreen} for why that is the additive
     * case, not a special one.
     */
    public function show(Occurrence $occurrence, Request $request, SessionScreen $screen): Response
    {
        Gate::authorize('log', $occurrence);

        $occurrence->loadMissing('action');

        $timezone = $request->user()->timezone ?? (string) config('app.timezone');

        return Inertia::render('training/session', [
            'occurrence_id' => $occurrence->id,
            'action_id' => $occurrence->action_id,
            'action_title' => $occurrence->action->title,
            'scheduled_for' => $occurrence->scheduled_for->timezone($timezone)->toIso8601String(),
            'exercises' => collect($screen->for($occurrence))
                ->map(fn (array $row): array => [
                    'id' => $row['exercise']->id,
                    'name' => $row['exercise']->name,
                    'target_sets' => $row['target_sets'],
                    'target_reps' => $row['target_reps'],
                    'performed_count' => $row['performed_sets']->count(),
                ])
                ->values()
                ->all(),
        ]);
    }
}
