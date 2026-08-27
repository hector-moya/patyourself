<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Companion\CompanionResolver;
use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;
use Illuminate\Http\Request;
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
 * `TodaysOccasions` returns unlogged occasions, so a row that gets an outcome
 * leaves the screen rather than becoming a tick. What has been dealt with stops
 * asking; the loop's own record keeps it.
 */
class NotebookController extends Controller
{
    public function index(Request $request, TodaysOccasions $todaysOccasions, CompanionResolver $companion): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? (string) config('app.timezone');

        return Inertia::render('dashboard', [
            'today' => Date::now($timezone)->toDateString(),
            'occasions' => $todaysOccasions->for($user)
                ->map(fn (TodaysOccasion $occasion): array => [
                    // Null for an anchored action with no materialised slot.
                    // The row picks its endpoint from this: with an id it posts
                    // to occurrences/{id}/logs, without one to actions/{id}/logs.
                    // Routing everything to the action route would log the live
                    // slot rather than the occasion on screen, and say nothing.
                    'occurrence_id' => $occasion->occurrence?->id,
                    'action_id' => $occasion->action->id,
                    'loop_id' => $occasion->action->intention_id,
                    'loop_title' => $occasion->action->intention->title,
                    'title' => $occasion->action->title,
                    'description' => $occasion->action->description,
                    'due' => $occasion->due,
                    'scheduled_for' => $occasion->scheduledFor?->timezone($timezone)->toIso8601String(),
                ])->values()->all(),
            'ready_for_verdict' => $this->readyForVerdict($request),
            // Blob rides along in the corner. Derived, so it costs a read and
            // there is nothing on this screen to keep in step with it.
            'companion' => $companion->forUser($user)->toArray(),
        ]);
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
     * @return list<array{loop_id: int, loop_title: string, version: int, intervention_point: string, day_of_experiment: int, planned_days: ?int}>
     */
    private function readyForVerdict(Request $request): array
    {
        return $request->user()->intentions()
            ->where('status', Intention::STATUS_ACTIVE)
            ->with('activeStrategy')
            ->get()
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
