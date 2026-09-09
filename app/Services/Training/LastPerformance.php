<?php

namespace App\Services\Training;

use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use Carbon\CarbonImmutable;

/**
 * The exercise screen's read model: what was lifted last time, for one
 * exercise.
 *
 * A pure read, and deliberately a thin one — no suggested weight, no record
 * detection, no 1RM, no fraction of one, no trend called progress. The spec says
 * the only thing a suggestion would give you is last session's own numbers,
 * and those can simply be on the screen.
 *
 * "Last" excludes the occasion the caller names: the exercise screen calls
 * this while a session is still in progress, and that occasion's own
 * not-yet-finished sets are not "last time" — they are what set-grid is
 * already showing on screen. Scoped to the occasion's owner, because the
 * exercise catalogue is shared and a bare read by exercise id would hand one
 * user's numbers to another.
 *
 * The sort carries a tiebreaker on `occurrences.id`. This method hands back
 * exactly one occasion as "last", and two occasions can share a
 * `scheduled_for` — different actions, same slot, the same exercise recorded
 * in both routines. Without the tiebreaker, which single occasion wins that
 * choice is whatever order the engine returns, and that order differs
 * between SQLite here and MySQL in production.
 *
 * Two bounded queries, whatever the history's size: one to find the occasion,
 * one to read its sets. The occasion lookup is a join rather than a
 * `pluck('occurrence_id')` fed back in as a `whereKey(...)`. That earlier shape
 * selected every occurrence id in `performed_sets` for this exercise across
 * every user — the catalogue is shared, so that set grows with everyone's
 * training, not just this user's — pulled the lot into PHP to deduplicate, and
 * then sent it straight back to the database. Note `whereKey()` on a collection
 * of integers routes to `whereIntegerInRaw`, so those ids were interpolated
 * into the statement as literals rather than bound: the SQL text itself grew
 * with the table, which is why neither a query count nor a binding count could
 * see it. Batch 3's progression screen runs this read once per exercise on the
 * page, which is what makes the difference worth having now.
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

        $lastOccurrence = Occurrence::query()
            ->select('occurrences.*')
            ->join('performed_sets', 'performed_sets.occurrence_id', '=', 'occurrences.id')
            ->join('actions', 'actions.id', '=', 'occurrences.action_id')
            ->join('intentions', 'intentions.id', '=', 'actions.intention_id')
            ->where('performed_sets.exercise_id', $exercise->id)
            ->where('intentions.user_id', $userId)
            ->whereKeyNot($excluding->id)
            ->orderByDesc('occurrences.scheduled_for')
            ->orderByDesc('occurrences.id')
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
