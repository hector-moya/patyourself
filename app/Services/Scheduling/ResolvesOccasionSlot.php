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

        return $this->latestUnloggedSlotToday($action, $now) ?? $this->freeSlotAt($action, $now);
    }

    /**
     * The occasion a session that is *starting* belongs to: today's, due or not
     * yet due. Latest first, on the same rule as liveSlotFor().
     *
     * The difference is the ceiling, and it is the whole reason this method
     * exists. A verdict is resolved at the moment it is pressed and logs the slot
     * it just resolved, so bounding it to slots already due is correct: you do
     * not log a session you have not had. A recording session resolves at the
     * start and is logged at the end, and warming up at 18:00 for a 19:00 slot is
     * ordinary — under liveSlotFor()'s ceiling tonight's slot is invisible, so a
     * phantom 18:00 occasion gets minted beside it, the sets hang off the
     * phantom, and the real slot is stranded unlogged on /catch-up.
     *
     * Falls through to a slot stamped now on the same terms: a cue-anchored
     * action has no grid, and a day whose slots are all logged has none left.
     */
    public function todaysSlotFor(Action $action): Occurrence
    {
        return $this->latestUnloggedSlotToday($action) ?? $this->freeSlotAt($action, Date::now());
    }

    /**
     * The action's latest unlogged occasion inside its owner's local day, or
     * null when the day holds none.
     *
     * `$notAfter` narrows that day to the part of it that has already happened.
     * Absent, the window is the whole local day — the difference between the two
     * public methods above, and the only difference between them.
     */
    private function latestUnloggedSlotToday(Action $action, ?DateTimeInterface $notAfter = null): ?Occurrence
    {
        $timezone = $action->intention?->user?->timezone ?? (string) config('app.timezone');
        $localNow = Date::now($timezone);

        return $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '>=', $localNow->copy()->startOfDay()->utc())
            ->where('scheduled_for', '<=', $notAfter ?? $localNow->copy()->endOfDay()->utc())
            ->orderByDesc('scheduled_for')
            ->first();
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
