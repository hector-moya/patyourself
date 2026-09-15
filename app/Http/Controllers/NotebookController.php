<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Companion\CompanionResolver;
use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The daily-driver screen at `/dashboard` — what the user is doing today, and
 * which experiment is waiting on a verdict.
 *
 * Today means the user's local day and only that. An occasion missed on an
 * earlier day never appears here: it stays loggable forever on `/catch-up`, and
 * surfacing it would turn the first screen after login into a backlog.
 *
 * Both halves of the day are sent — what is still open, and what has already
 * been answered. The screen is a timeline, and a timeline that dropped the
 * hours already dealt with would reset itself as the day went on: at 21:00 a
 * day where everything was recorded on time would look identical to one where
 * nothing was ever scheduled. An answered occasion is carried with its outcome
 * and reads as settled, which is not the same as asking again.
 */
class NotebookController extends Controller
{
    public function index(Request $request, TodaysOccasions $todaysOccasions, CompanionResolver $companion): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? (string) config('app.timezone');

        // One read of the active loops, shared by the per-occasion strategy
        // label and the verdict list below. They ask the same question of the
        // same rows, and asking it twice is two queries that can disagree.
        $activeLoops = $user->intentions()
            ->where('status', Intention::STATUS_ACTIVE)
            ->with('activeStrategy')
            ->get();

        $occasions = $todaysOccasions->for($user)
            ->concat($todaysOccasions->recordedToday($user))
            // Time order, with the cue-anchored entries last. They have no
            // scheduled_for to place them by — an anchored action is offered
            // for the whole day, so the timeline shows it after the clock
            // rather than inventing an hour for it.
            ->sortBy(fn (TodaysOccasion $occasion): array => [
                $occasion->scheduledFor === null ? 1 : 0,
                $occasion->scheduledFor?->getTimestamp() ?? 0,
            ])
            ->values();

        return Inertia::render('dashboard', [
            'today' => Date::now($timezone)->toDateString(),
            // Where the timeline draws its "now" rule. Sent rather than read
            // off the browser clock so it is the same clock that decided each
            // occasion's `due` above — a device clock running minutes off would
            // otherwise put the rule on the wrong side of a row the server has
            // already called due.
            'now' => Date::now($timezone)->toIso8601String(),
            'occasions' => $occasions
                ->map(fn (TodaysOccasion $occasion): array => $this->occasionPayload($occasion, $activeLoops, $timezone))
                ->values()->all(),
            'ready_for_verdict' => $this->readyForVerdict($activeLoops),
            // Blob rides along in the corner. Derived, so it costs a read and
            // there is nothing on this screen to keep in step with it.
            'companion' => $companion->forUser($user)->toArray(),
            // The outcome recorded on the request that redirected here, if any.
            // Session flash, so it is gone by the next request and coming back
            // to this screen later never replays the reaction.
            'logged_outcome_id' => $request->session()->get('logged_outcome_id'),
        ]);
    }

    /**
     * One row of the timeline.
     *
     * @param  Collection<int, Intention>  $activeLoops
     * @return array{occurrence_id: ?int, action_id: int, loop_id: int, loop_title: string, workflow: ?string, title: string, description: ?string, due: string, scheduled_for: ?string, strategy: ?string, outcome: ?string}
     */
    private function occasionPayload(TodaysOccasion $occasion, Collection $activeLoops, string $timezone): array
    {
        $loopId = $occasion->action->intention_id;

        return [
            // Null for an anchored action with no materialised slot. The row
            // picks its endpoint from this: with an id it posts to
            // occurrences/{id}/logs, without one to actions/{id}/logs. Routing
            // everything to the action route would log the live slot rather
            // than the occasion on screen, and say nothing about it.
            'occurrence_id' => $occasion->occurrence?->id,
            'action_id' => $occasion->action->id,
            'loop_id' => $loopId,
            'loop_title' => $occasion->action->intention->title,
            // Which recording surface this loop uses, or null for the plain
            // screen. Always sent, so the client routes on a value rather than
            // inferring from an absent key.
            'workflow' => $occasion->action->intention->workflow,
            'title' => $occasion->action->title,
            'description' => $occasion->action->description,
            'due' => $occasion->due,
            'scheduled_for' => $occasion->scheduledFor?->timezone($timezone)->toIso8601String(),
            // Which version of which intervention this occasion is testing —
            // the answer to "why am I being asked this", at the point of being
            // asked. Null for a loop with no running experiment, which is a
            // legitimate state: logging continues without one.
            'strategy' => $this->strategyLabel($activeLoops->firstWhere('id', $loopId)),
            // Null while the occasion is open. Set means this row is a record
            // of what happened, not a question.
            'outcome' => $occasion->log?->outcome,
        ];
    }

    /** "v2 · after breakfast", or null when the loop has no running experiment. */
    private function strategyLabel(?Intention $loop): ?string
    {
        $strategy = $loop?->activeStrategy;

        return $strategy instanceof Strategy
            ? sprintf('v%d · %s', $strategy->version, $strategy->intervention_point)
            : null;
    }

    /**
     * Active loops whose current version has reached its review date.
     *
     * Asks `isUnderReview()` rather than comparing `review_at` here: that method
     * already excludes a superseded or retired version with a past date, and an
     * open-ended version with no date at all. Re-deriving the rule from the
     * column would quietly disagree with the rest of the app.
     *
     * This is not a nag. A version past its review date is not late — nothing in
     * this app is — it simply means a decision is available.
     *
     * @param  Collection<int, Intention>  $activeLoops
     * @return list<array{loop_id: int, loop_title: string, version: int, intervention_point: string, day_of_experiment: int, planned_days: ?int}>
     */
    private function readyForVerdict(Collection $activeLoops): array
    {
        return $activeLoops
            ->filter(fn (Intention $loop): bool => $loop->activeStrategy instanceof Strategy
                && $loop->activeStrategy->isUnderReview())
            ->map(fn (Intention $loop): array => [
                'loop_id' => $loop->id,
                'loop_title' => $loop->title,
                'version' => $loop->activeStrategy->version,
                'intervention_point' => $loop->activeStrategy->intervention_point,
                'day_of_experiment' => $loop->activeStrategy->dayOfExperiment(),
                'planned_days' => $loop->activeStrategy->plannedDays(),
            ])->values()->all();
    }
}
