<?php

namespace App\Http\Controllers;

use App\Actions\ConcludeExperiment;
use App\Http\Requests\StoreVerdictRequest;
use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ends an experiment with a verdict from the lab record.
 *
 * The dashboard already tells the user which experiment has reached its review
 * date; without this route it asks the question and offers no way to answer it.
 */
class VerdictController extends Controller
{
    public function store(StoreVerdictRequest $request, Strategy $strategy, ConcludeExperiment $conclude): RedirectResponse
    {
        Gate::authorize('update', $strategy);

        try {
            $conclude->handle(
                $strategy,
                $request->string('verdict')->toString(),
                // Verbatim: never trimmed or sentence-cased.
                $request->input('note'),
            );
        } catch (StrategyTransitionException $e) {
            // Realistically two tabs, not a malformed request — so it belongs on
            // the form rather than in a 500.
            throw ValidationException::withMessages(['verdict' => $e->getMessage()]);
        }

        return back();
    }
}
