<?php

namespace App\Http\Controllers;

use App\Actions\LearnSkill;
use App\Models\Companion;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Spending the record on a skill — the one consequential choice in the feature.
 *
 * A short balance is an ordinary state of the world, not an error: it comes
 * back as a line rather than a 500, and the skill stays listed with its price
 * so nothing about the refusal reads as a lock.
 *
 * The bag stays open across this. It is posted from inside the dialog, and an
 * Inertia visit re-renders the page — so the flag that keeps it up rides back
 * with the response, or you would buy a skill and watch the bag vanish before
 * you saw what changed.
 */
class CompanionSkillController extends Controller
{
    public function store(Request $request, LearnSkill $learn): RedirectResponse
    {
        $validated = $request->validate([
            'skill' => ['required', 'string', Rule::in(array_keys((array) config('companion.skills', [])))],
        ]);

        $skill = (string) $validated['skill'];
        $name = Companion::nameFor($request->user());

        try {
            $learned = $learn->handle($request->user(), $skill);
        } catch (CompanionEconomyException) {
            return back()
                ->with(
                    CompanionController::SAID_KEY,
                    "{$name} has not worked out how to do that yet.",
                )
                ->with(CompanionController::STAY_KEY, true);
        }

        $label = (string) (config("companion.skills.{$skill}.label") ?? $skill);

        return back()
            ->with(CompanionController::SAID_KEY, "{$name} has learned to {$label}.")
            ->with(CompanionController::STAY_KEY, true)
            ->with('learned', $learned->name);
    }
}
