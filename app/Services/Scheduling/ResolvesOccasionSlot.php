<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Occurrence;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use RuntimeException;

/**
 * Which occasion an action means right now, for a caller that names none.
 *
 * Two paths need this answer, and they ask different questions of it.
 * App\Actions\LogAction records an outcome against "the action" and wants the
 * slot whose moment has passed; App\Services\Workflows\MaterialisesOccasion
 * begins a recording session and wants today's slot whether or not its moment
 * has arrived. One method each, sharing the one thing they do agree on —
 * freeSlotAt() — rather than a second implementation of it.
 *
 * Both callers are named in prose rather than imported: this is the lower-level
 * collaborator, and a `use` block pointing up at the two layers that consume it
 * inverts the dependency for the sake of a doc tag.
 */
final readonly class ResolvesOccasionSlot
{
    /**
     * The occasion a caller means when it names none: today's, which is what a
     * card on screen is about. Latest first, so a day with two slots resolves
     * the later one — the one whose moment has most recently passed.
     *
     * A cue-anchored action has no grid, and a day whose slots are all logged
     * has none left, so both fall through to a slot stamped now. That is how a
     * second log on an already-answered day is recorded as its own occasion
     * rather than colliding with the first.
     */
    public function liveSlotFor(Action $action): Occurrence
    {
        $now = Date::now();
        $timezone = $action->intention?->user?->timezone ?? (string) config('app.timezone');
        $localNow = Date::now($timezone);

        $slot = $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '<=', $now)
            ->where('scheduled_for', '>=', $localNow->copy()->startOfDay()->utc())
            ->orderByDesc('scheduled_for')
            ->first();

        return $slot ?? $this->freeSlotAt($action, $now);
    }

    /**
     * The first unlogged occasion at or after `$from` for this action. Occasions
     * are stored to the second, so two logs made inside the same second would
     * otherwise collide on the unique (action_id, scheduled_for) index.
     */
    private function freeSlotAt(Action $action, DateTimeInterface $from): Occurrence
    {
        $stamp = CarbonImmutable::instance($from)->startOfSecond();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $slot = Occurrence::query()->firstOrCreate([
                'action_id' => $action->id,
                'scheduled_for' => $stamp,
            ]);

            if (! $slot->isLogged()) {
                return $slot;
            }

            $stamp = $stamp->addSecond();
        }

        throw new RuntimeException('Could not find a free occurrence slot for action '.$action->id.'.');
    }
}
