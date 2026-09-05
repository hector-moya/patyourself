<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The one definition of "what is due today" for a user: unlogged occasions
 * inside the user's local day, plus cue-anchored actions, which have no
 * schedule and so no occasion to be inside it.
 *
 * The window is the whole point. Occasions never expire — a missed one stays
 * loggable forever — so selecting every unlogged past occasion would build a
 * backlog and turn the digest into a nag. Yesterday's misses are reachable
 * only from /catch-up, which the user goes looking for.
 *
 * `due` is derived from the clock, not from whether a cue was delivered:
 * `fired_at` is the trigger engine's idempotency guard and nothing else.
 *
 * Shared so the daily digest, the today-actions tool and the action cards can
 * never disagree about what the user owes today.
 */
class TodaysOccasions
{
    public function __construct(private readonly MaterialiseOccurrences $materialise) {}

    /**
     * @return Collection<int, TodaysOccasion>
     */
    public function for(User $user): Collection
    {
        // Lazy as ever: today's grid is built on the read that needs it. This
        // is not a write side-effect of logging — nothing here can conjure an
        // occasion the check-in then asks about that the schedule did not
        // already imply.
        $this->materialise->forUser($user);

        $timezone = $user->timezone ?? (string) config('app.timezone');
        $now = Date::now();
        $localNow = Date::now($timezone);

        $scheduled = Occurrence::query()
            ->unlogged()
            ->whereBetween('scheduled_for', [
                $localNow->copy()->startOfDay()->utc(),
                $localNow->copy()->endOfDay()->utc(),
            ])
            ->whereHas('action', fn (Builder $query) => $this->restrictToUsersActiveLoops($query, $user))
            ->with('action.intention:id,title,workflow')
            ->orderBy('scheduled_for')
            ->get()
            ->map(fn (Occurrence $occurrence): TodaysOccasion => new TodaysOccasion(
                action: $occurrence->action,
                occurrence: $occurrence,
                scheduledFor: $occurrence->scheduled_for,
                due: $occurrence->scheduled_for->lessThanOrEqualTo($now)
                    ? TodaysOccasion::DUE_NOW
                    : TodaysOccasion::UPCOMING,
            ));

        $anchoredQuery = Action::query()->whereNull('series_started_at');
        $this->restrictToUsersActiveLoops($anchoredQuery, $user);

        $anchored = $anchoredQuery
            ->with('intention:id,title,workflow')
            ->orderBy('id')
            ->get()
            ->map(fn (Action $action): TodaysOccasion => new TodaysOccasion(
                action: $action,
                occurrence: null,
                scheduledFor: null,
                due: TodaysOccasion::ANCHORED,
            ));

        return $scheduled->concat($anchored)->values();
    }

    /**
     * An action counts today only if it still belongs to a loop this user is
     * actively working: not archived itself, and owned by one of the user's
     * active loops. Applied identically to the scheduled and cue-anchored
     * branches so a future change to either cannot silently drift from the
     * other and leak between them.
     *
     * @param  Builder<Action>  $actions
     */
    private function restrictToUsersActiveLoops(Builder $actions, User $user): void
    {
        $actions
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->whereHas('intention', fn (Builder $loop) => $loop
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE));
    }
}
