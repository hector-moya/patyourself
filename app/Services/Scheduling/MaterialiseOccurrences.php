<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Turns an action's standing schedule into the concrete occasions it has
 * produced so far.
 *
 * Lazy: this runs on a read path and never as a side effect of a write, so
 * logging an outcome can never quietly conjure rows.
 *
 * Idempotent and safe under concurrent reads — rows go in through an upsert
 * against the unique (action_id, scheduled_for) index that updates nothing on
 * conflict, so an overlapping run writes no duplicates, needs no lock, and
 * leaves an already-logged occasion exactly as it is.
 *
 * Walks forward from the action's `series_started_at` with Schedule::advance(),
 * which preserves wall-clock time in the user's timezone and so stays
 * DST-correct and keeps weekly's weekday.
 */
final readonly class MaterialiseOccurrences
{
    /** One pass stops here, so a very old anchor cannot run away. */
    public const MAX_SLOTS_PER_ACTION = 1000;

    public function __construct(private Schedule $schedule) {}

    /**
     * Materialise across every active loop this user owns. Returns the number
     * of occasions created.
     */
    public function forUser(User $user): int
    {
        return $this->run(
            Action::query()->whereHas(
                'intention',
                fn (Builder $query) => $query
                    ->where('user_id', $user->id)
                    ->where('status', Intention::STATUS_ACTIVE),
            ),
            $user->timezone ?? (string) config('app.timezone'),
        );
    }

    /**
     * Materialise one loop. A paused or archived loop materialises nothing —
     * its occasions are not occasions the user is being asked about.
     */
    public function forLoop(Intention $loop): int
    {
        if (! $loop->isActive()) {
            return 0;
        }

        return $this->run(
            $loop->actions()->getQuery(),
            $loop->user?->timezone ?? (string) config('app.timezone'),
        );
    }

    /**
     * @param  Builder<Action>  $actions
     */
    private function run(Builder $actions, string $timezone): int
    {
        $eligible = $actions
            ->whereNotNull('series_started_at')
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->get();

        $created = 0;

        foreach ($eligible as $action) {
            $created += $this->materialise($action, $timezone);
        }

        return $created;
    }

    /**
     * The grid this action has produced through the end of the user's local
     * day. The horizon is end-of-day rather than `now` because the today list
     * splits into due_now and upcoming, and "upcoming" needs real rows to
     * select — a horizon at `now` makes that half of the list permanently
     * empty.
     *
     * The walk always restarts from the anchor rather than resuming from the
     * last materialised slot: `RescheduleAction` re-anchors, and the old grid
     * and the new one do not share a phase, so resuming would continue the
     * abandoned cadence.
     *
     * Only slots that do not already exist are written. This runs every minute
     * from `actions:fire`, and re-upserting up to MAX_SLOTS_PER_ACTION rows per
     * action per minute is pure waste; in the steady state the diff is empty
     * and the method returns before touching the database at all.
     */
    private function materialise(Action $action, string $timezone): int
    {
        $horizon = CarbonImmutable::now($timezone)->endOfDay()->utc();
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $slots = [];
        $slot = $action->series_started_at->toImmutable();

        while ($slot->lessThanOrEqualTo($horizon) && count($slots) < self::MAX_SLOTS_PER_ACTION) {
            $slots[] = $slot->utc()->toDateTimeString();

            $next = $this->schedule->advance($slot, $recurrence, $timezone);

            // A one-off has no next slot: it produces exactly its anchor.
            if ($next === null) {
                break;
            }

            $slot = $next;
        }

        if ($slots === []) {
            return 0;
        }

        $existing = $action->occurrences()
            ->whereIn('scheduled_for', $slots)
            ->pluck('scheduled_for')
            ->map(fn (CarbonImmutable $stamp): string => $stamp->utc()->toDateTimeString())
            ->all();

        $missing = array_values(array_diff($slots, $existing));

        if ($missing === []) {
            return 0;
        }

        $before = $action->occurrences()->count();

        // Still an upsert, not an insert: the diff above narrows the write, but
        // two overlapping runs can both see the same slot missing. The unique
        // (action_id, scheduled_for) index and "update nothing on conflict" are
        // what make that a no-op rather than a duplicate or an error.
        Occurrence::query()->upsert(
            array_map(fn (string $stamp): array => [
                'action_id' => $action->id,
                'scheduled_for' => $stamp,
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ], $missing),
            ['action_id', 'scheduled_for'],
            [],
        );

        return $action->occurrences()->count() - $before;
    }
}
