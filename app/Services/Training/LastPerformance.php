<?php

namespace App\Services\Training;

use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The exercise screen's read model: what was lifted last time, for one
 * exercise.
 *
 * A pure read, and deliberately a thin one — no suggested weight, no record
 * detection, no 1RM, no percentage, no trend called progress. The spec says
 * the only thing a suggestion would give you is last session's own numbers,
 * and those can simply be on the screen.
 *
 * "Last" excludes the occasion the caller names: the exercise screen calls
 * this while a session is still in progress, and that occasion's own
 * not-yet-finished sets are not "last time" — they are what set-grid is
 * already showing on screen. Scoped to the occasion's owner, because the
 * exercise catalogue is shared and a bare read by exercise id would hand one
 * user's numbers to another.
 */
class LastPerformance
{
    /**
     * @return array{
     *     performed_at: CarbonImmutable,
     *     sets: list<array{reps: int, weight: float|null}>,
     * }|null
     */
    public function forExercise(Exercise $exercise, Occurrence $excluding): ?array
    {
        $userId = $excluding->action->intention->user_id;

        $occurrenceIds = PerformedSet::query()
            ->where('exercise_id', $exercise->id)
            ->where('occurrence_id', '!=', $excluding->id)
            ->pluck('occurrence_id')
            ->unique();

        if ($occurrenceIds->isEmpty()) {
            return null;
        }

        $lastOccurrence = Occurrence::query()
            ->whereKey($occurrenceIds)
            ->whereHas('action.intention', fn (Builder $query) => $query->where('user_id', $userId))
            ->orderByDesc('scheduled_for')
            ->first();

        if ($lastOccurrence === null) {
            return null;
        }

        $sets = PerformedSet::query()
            ->where('occurrence_id', $lastOccurrence->id)
            ->where('exercise_id', $exercise->id)
            ->orderBy('set_number')
            ->get();

        return [
            'performed_at' => $lastOccurrence->scheduled_for,
            'sets' => $sets->map(fn (PerformedSet $set): array => [
                'reps' => $set->reps,
                'weight' => $set->weight === null ? null : (float) $set->weight,
            ])->all(),
        ];
    }
}
