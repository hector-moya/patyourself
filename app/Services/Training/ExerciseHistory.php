<?php

namespace App\Services\Training;

use App\Models\Exercise;
use App\Models\PerformedSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The progression screen's read model: every occasion that recorded this
 * exercise, newest first, with the sets it carried.
 *
 * A pure read, and deliberately a thin one — no record detection, no 1RM, no
 * volume total, no fraction of one, no trend called progress. This is the
 * screen where the temptation is strongest, so the line is drawn hardest
 * here: showing what was lifted is recording; saying what it means is
 * coaching.
 *
 * One query, whatever the history's length. The occasion is reached by
 * joining rather than by reading `$set->occurrence` per row, which is the
 * N+1 this module is likeliest to grow — the sibling read
 * {@see LastPerformance} records why the earlier `pluck`-and-feed-back shape
 * was worse still.
 *
 * Scoped to the user through `intentions.user_id`, because the catalogue is
 * shared: a bare read by exercise id would hand one person's training to
 * another.
 *
 * The sort carries a tiebreaker on `occurrences.id`. Two occasions can share
 * a `scheduled_for` — different actions, same slot — and without it their
 * order is whatever the engine happens to return, which differs between
 * SQLite here and MySQL in production.
 *
 * Nothing filters on whether the occasion was logged. What was lifted is what
 * was lifted; the verdict is a separate fact about the occasion and does not
 * decide whether the sets happened.
 */
class ExerciseHistory
{
    /**
     * @return list<array{
     *     occurrence_id: int,
     *     performed_at: CarbonImmutable,
     *     sets: list<array{reps: int, weight: float|null}>,
     * }>
     */
    public function forExercise(Exercise $exercise, User $user): array
    {
        $rows = PerformedSet::query()
            ->select([
                'performed_sets.occurrence_id',
                'performed_sets.set_number',
                'performed_sets.reps',
                'performed_sets.weight',
                'occurrences.scheduled_for as occasion_scheduled_for',
            ])
            ->join('occurrences', 'occurrences.id', '=', 'performed_sets.occurrence_id')
            ->join('actions', 'actions.id', '=', 'occurrences.action_id')
            ->join('intentions', 'intentions.id', '=', 'actions.intention_id')
            ->where('performed_sets.exercise_id', $exercise->id)
            ->where('intentions.user_id', $user->id)
            ->orderByDesc('occurrences.scheduled_for')
            ->orderByDesc('occurrences.id')
            // Not redundant, even though no test can prove it: SQLite's
            // covering unique index on (occurrence_id, exercise_id,
            // set_number) already returns rows in this order on its own, so
            // deleting this line stays green here and breaks ordering only
            // on MySQL in production.
            ->orderBy('performed_sets.set_number')
            ->get();

        return $rows
            ->groupBy('occurrence_id')
            ->map(fn (Collection $sets, int|string $occurrenceId): array => [
                'occurrence_id' => (int) $occurrenceId,
                'performed_at' => CarbonImmutable::parse(
                    $sets->first()->getAttribute('occasion_scheduled_for'),
                ),
                'sets' => $sets->map(fn (PerformedSet $set): array => [
                    'reps' => $set->reps,
                    'weight' => $set->weight === null ? null : (float) $set->weight,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
