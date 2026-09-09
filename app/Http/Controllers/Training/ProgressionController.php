<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Services\Training\ExerciseHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The progression screen: what was lifted on this exercise, for how many
 * reps, on what date — newest first.
 *
 * Keyed on the exercise alone, not on an occasion: this is the one screen in
 * the module that is about a movement across sessions rather than about one
 * session, and it is reachable outside a session for that reason.
 *
 * The catalogue is shared, so owning an account says nothing about owning an
 * exercise. Scoped by {@see Exercise::availableTo()}, the same rule the
 * routine and set-writing requests already apply, and refused as a 404 rather
 * than a 403 for the same reason they are: an exercise outside your catalogue
 * is one that does not exist as far as you are concerned, and a 403 would
 * confirm the id is real. The history itself is scoped again inside
 * {@see ExerciseHistory}, by the owning loop — the two are different
 * questions and both have to be asked.
 *
 * Record, never prescribe. There is no chart, no record detection, no 1RM, no
 * volume total, no fraction of one, and no trend named. The screen states what
 * happened and stops.
 */
class ProgressionController extends Controller
{
    public function show(Exercise $exercise, Request $request, ExerciseHistory $history): Response
    {
        abort_unless(
            Exercise::query()->availableTo($request->user())->whereKey($exercise->id)->exists(),
            404,
        );

        $timezone = $request->user()->timezone ?? (string) config('app.timezone');

        return Inertia::render('training/progression', [
            'exercise' => [
                'id' => $exercise->id,
                'name' => $exercise->name,
            ],
            'sessions' => collect($history->forExercise($exercise, $request->user()))
                ->map(fn (array $session): array => [
                    'occurrence_id' => $session['occurrence_id'],
                    'performed_at' => $session['performed_at']->timezone($timezone)->toIso8601String(),
                    'sets' => $session['sets'],
                ])
                ->all(),
        ]);
    }
}
