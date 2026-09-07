<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\ActionExercise;
use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Services\Training\LastPerformance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The exercise screen: what to lift, what was lifted last time, and where
 * sets against this occasion get recorded.
 *
 * Keyed on the occurrence, not the action, matching every other record-side
 * seam in this module (`PerformedSetController`, `OccurrenceLogController`,
 * `SessionController::show`) — a set is a fact about one occasion.
 *
 * Record, never prescribe: the routine row supplies the target sets/reps the
 * user wrote onto the routine, `LastPerformance` supplies last session's own
 * numbers as pure information, and `PerformedSet` supplies what is already
 * recorded this occasion. Nothing computed here is a suggested weight, a
 * record detection, a 1RM, or a percentage — see `LastPerformance`'s own
 * docblock for why that read stays a read.
 */
class ExerciseController extends Controller
{
    public function show(Occurrence $occurrence, Exercise $exercise, Request $request, LastPerformance $lastPerformance): Response
    {
        Gate::authorize('log', $occurrence);

        $routine = ActionExercise::query()
            ->where('action_id', $occurrence->action_id)
            ->where('exercise_id', $exercise->id)
            ->first();

        $performedSets = PerformedSet::query()
            ->where('occurrence_id', $occurrence->id)
            ->where('exercise_id', $exercise->id)
            ->orderBy('set_number')
            ->get();

        $timezone = $request->user()->timezone ?? (string) config('app.timezone');
        $last = $lastPerformance->forExercise($exercise, $occurrence);

        return Inertia::render('training/exercise', [
            'occurrence_id' => $occurrence->id,
            'exercise' => [
                'id' => $exercise->id,
                'name' => $exercise->name,
                'target_sets' => $routine?->target_sets,
                'target_reps' => $routine?->target_reps,
                'instructions' => $exercise->instructions ?? [],
                'image_path' => $exercise->image_path,
            ],
            'performed_sets' => $performedSets
                ->map(fn (PerformedSet $set): array => [
                    'reps' => $set->reps,
                    'weight' => $set->weight === null ? null : (float) $set->weight,
                ])
                ->values()
                ->all(),
            'last' => $last === null ? null : [
                'performed_at' => $last['performed_at']->timezone($timezone)->toIso8601String(),
                'sets' => $last['sets'],
            ],
        ]);
    }
}
