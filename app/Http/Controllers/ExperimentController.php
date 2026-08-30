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
                changeReason: $request->input('change_reason') ?? $this->defaultChangeReason($current),
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

    /**
     * Guesses why the outgoing version is being superseded, for the case where
     * the form did not say. The form has no change_reason field — the MCP tool
     * takes it explicitly because the model can be asked, but this boundary
     * cannot ask the user a follow-up question mid-submit.
     *
     * History is append-only: a version's change_reason is never edited after
     * the fact, so a wrong guess here is permanent, not merely wrong until
     * corrected. stacked_on_success is only safe to guess when the outgoing
     * version's own verdict says `worked` — that is a fact already on the
     * record, not an inference. Anything else (no verdict yet, or `failed`)
     * has no recorded success to build on, so it defaults to
     * restrategized_on_failure rather than claim one.
     */
    private function defaultChangeReason(Strategy $current): string
    {
        return $current->verdict === Strategy::VERDICT_WORKED
            ? Strategy::REASON_STACKED_ON_SUCCESS
            : Strategy::REASON_RESTRATEGIZED_ON_FAILURE;
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
