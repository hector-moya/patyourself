<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Occurrence;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The exercise screen — task 7's own build, named and routed now.
 *
 * `session.tsx` links to one exercise within a session, and that link has to
 * go through a real Wayfinder helper rather than a hardcoded URL. A route
 * needs a resolvable controller method to generate one from, so this is
 * that minimum: authorization and the two ids the eventual screen needs,
 * nothing task 7 owns (the read model, the set form, `LastPerformance`).
 *
 * Keyed on the occurrence, not the action, matching every other record-side
 * seam in this module (`PerformedSetController`, `OccurrenceLogController`,
 * `SessionController::show`) — a set is a fact about one occasion.
 */
class ExerciseController extends Controller
{
    public function show(Occurrence $occurrence, Exercise $exercise): Response
    {
        Gate::authorize('log', $occurrence);

        return Inertia::render('training/exercise', [
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
        ]);
    }
}
