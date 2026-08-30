<?php

namespace App\Http\Controllers;

use App\Actions\StartExperiment;
use App\Http\Requests\StoreExperimentRequest;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Authoring\AuthoredAction;
use App\Services\Authoring\AuthoredStrategy;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Starts the next experiment on a loop from the lab record.
 *
 * Authoring a loop stays with the coach — a chain the user did not talk through
 * describes nothing. An experiment is different: the alternative to this form
 * was tinker.
 */
class ExperimentController extends Controller
{
    public function store(StoreExperimentRequest $request, Intention $intention, StartExperiment $start): RedirectResponse
    {
        Gate::authorize('update', $intention);

        $current = $intention->activeStrategy;

        if (! $current instanceof Strategy) {
            throw ValidationException::withMessages([
                'intervention_point' => 'This loop has no active experiment to supersede. Activate the loop first.',
            ]);
        }

        try {
            $start->handle(
                current: $current,
                next: new AuthoredStrategy(
                    interventionPoint: $request->string('intervention_point')->toString(),
                    approach: $request->string('approach')->toString(),
                    rationale: $request->input('rationale'),
                ),
                changeReason: $request->input('change_reason', Strategy::REASON_RESTRATEGIZED_ON_FAILURE),
                supersededReason: $request->input('supersedes_reason'),
                reviewAfterDays: $request->filled('review_after_days')
                    ? $request->integer('review_after_days')
                    : null,
                // Null inherits the prior cadence. Only an explicit "change"
                // re-proposes it.
                revisedAction: $this->revisedAction($request),
            );
        } catch (StrategyTransitionException $e) {
            throw ValidationException::withMessages(['intervention_point' => $e->getMessage()]);
        }

        return back();
    }

    private function revisedAction(StoreExperimentRequest $request): ?AuthoredAction
    {
        if ($request->input('cadence') !== 'change') {
            return null;
        }

        $kind = $request->string('action_kind')->toString();

        return new AuthoredAction(
            title: $request->string('action_title')->toString(),
            description: null,
            kind: $kind,
            time: $kind === 'clock' ? $request->input('action_time') : null,
            recurrence: $kind === 'clock' ? $request->input('action_recurrence', 'once') : null,
            anchor: $kind === 'anchored' ? $request->input('action_anchor') : null,
        );
    }
}
