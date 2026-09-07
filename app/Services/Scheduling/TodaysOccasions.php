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
 * schedule and so usually no occasion to be inside it.
 *
 * Usually, not always. Beginning to record materialises an occasion for a
 * cue-anchored action — App\Services\Workflows\MaterialisesOccasion, named in
 * prose rather than imported so this layer does not reach up at the workflow
 * one for a doc tag — and from that moment the action belongs to the first half
 * of this list rather than the second. The two halves are exclusive by
 * construction, not by coincidence, and that is what the whereDoesntHave below
 * is for: two entries for one action mean two cards, two verdicts and logCount
 * +2 for a single session, which the unique index on action_logs.occurrence_id
 * cannot catch because they are two real, distinct occasions.
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

        // Held once and used by both branches. The anchored branch excludes
        // exactly what the scheduled branch selects, so the two can only agree
        // if they read the same window from the same variable.
        $localDay = [
            $localNow->copy()->startOfDay()->utc(),
            $localNow->copy()->endOfDay()->utc(),
        ];

        $scheduled = Occurrence::query()
            ->unlogged()
            ->whereBetween('scheduled_for', $localDay)
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

        // Cue-anchored, and not already standing in the scheduled half above.
        // `unlogged` and the window are both load-bearing, and each keeps a
        // different action on the list: a logged occasion means the day was
        // answered and a cue-anchored action is offerable again, and an
        // unlogged occasion from an earlier day belongs to /catch-up, which the
        // scheduled branch will not return. Widen this past today's unlogged
        // rows and the action falls out of both halves and off the day.
        //
        // One correlated subquery, not a relation read per action: three
        // surfaces call this service, so an N+1 here is paid three times.
        $anchoredQuery = Action::query()
            ->whereNull('series_started_at')
            ->whereDoesntHave('occurrences', fn (Builder $occasions) => $occasions
                ->unlogged()
                ->whereBetween('scheduled_for', $localDay));

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
