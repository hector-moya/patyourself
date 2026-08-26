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

    private function materialise(Action $action, string $timezone): int
    {
        $now = CarbonImmutable::now();
        $recurrence = Recurrence::tryFromToken($action->recurrence);

        $slots = [];
        $slot = $action->series_started_at->toImmutable();

        while ($slot->lessThanOrEqualTo($now) && count($slots) < self::MAX_SLOTS_PER_ACTION) {
            $slots[] = [
                'action_id' => $action->id,
                'scheduled_for' => $slot->utc()->toDateTimeString(),
                'created_at' => Date::now(),
                'updated_at' => Date::now(),
            ];

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

        $before = $action->occurrences()->count();

        Occurrence::query()->upsert($slots, ['action_id', 'scheduled_for'], []);

        return $action->occurrences()->count() - $before;
    }
}
