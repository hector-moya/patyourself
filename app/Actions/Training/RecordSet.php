<?php

namespace App\Actions\Training;

use App\Models\Exercise;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Services\Scheduling\ResolvesOccasionSlot;
use Illuminate\Support\Facades\DB;

/**
 * Records one set actually performed during a session — the gym workflow's
 * record extension site's only writer.
 *
 * Recording does not log: a {@see PerformedSet} never touches an ActionLog
 * or anything the companion's `logCount` reads from, however many sets a
 * session racks up. One occasion produces exactly one log, and the verdict
 * is a separate press, by a person, always — filling in three sets and then
 * marking the session missed because it was cut short is a real thing that
 * happens, and inferring "done" from the presence of data would overrule the
 * person who was there.
 *
 * `set_number` continues from whatever is already recorded for this
 * (occurrence, exercise) pair rather than restarting at 1. Two sessions on
 * the same day with no verdict pressed between them deliberately resolve to
 * the same occasion (see {@see ResolvesOccasionSlot}), so a second session
 * recording the same exercise must continue numbering or the insert collides
 * with the unique index on (occurrence_id, exercise_id, set_number). The max
 * is read with a locking read inside a transaction — the same hazard
 * {@see ResolvesOccasionSlot::freeSlotAt()} guards against for occasions —
 * so two quick taps on the same exercise cannot both compute the same next
 * number and race each other into that index.
 */
final readonly class RecordSet
{
    public function handle(Occurrence $occurrence, Exercise $exercise, int $reps, ?float $weight): PerformedSet
    {
        return DB::transaction(function () use ($occurrence, $exercise, $reps, $weight): PerformedSet {
            $lastSetNumber = (int) PerformedSet::query()
                ->where('occurrence_id', $occurrence->id)
                ->where('exercise_id', $exercise->id)
                ->lockForUpdate()
                ->max('set_number');

            return PerformedSet::create([
                'occurrence_id' => $occurrence->id,
                'exercise_id' => $exercise->id,
                'set_number' => $lastSetNumber + 1,
                'reps' => $reps,
                'weight' => $weight,
            ]);
        });
    }
}
