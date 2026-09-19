<?php

namespace App\Http\Controllers;

use App\Actions\BuildShelter;
use App\Models\Companion;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Putting up one stage of the shelter.
 *
 * Posted from inside the bag and leaves it open, the same as buying a skill or
 * building an item: the point of the press is to watch the thing appear.
 *
 * Every refusal comes back as one line. A stage the screen never offered — out
 * of order, or behind a floor the record has not reached — is deliberately
 * given the SAME line as a shortfall rather than one of its own, because there
 * is no honest sentence for it: the row was never on the screen, so a message
 * explaining why would be the app naming a thing it has been careful not to
 * name.
 */
class CompanionShelterController extends Controller
{
    public function store(Request $request, BuildShelter $build): RedirectResponse
    {
        $validated = $request->validate([
            'stage' => ['required', 'string', Rule::in(array_keys((array) config('companion.shelter', [])))],
        ]);

        $stage = (string) $validated['stage'];
        $name = Companion::nameFor($request->user());
        $label = (string) (config("companion.shelter.{$stage}.label") ?? $stage);

        try {
            $build->handle($request->user(), $stage);
        } catch (CompanionEconomyException) {
            return back()
                ->with(CompanionController::SAID_KEY, "There is not enough for a {$label} yet.")
                ->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                '{name}',
                $name,
                (string) config("companion.shelter.{$stage}.built"),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
