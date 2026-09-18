<?php

namespace App\Http\Controllers;

use App\Actions\BuildItem;
use App\Models\Companion;
use App\Services\Companion\CompanionCapacityException;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Building something out of what Blob is carrying.
 *
 * Needs no skill: assembling by hand is what hands are for.
 *
 * Short materials come back as a line, not an error — the recipe is a price,
 * and not having paid it yet is not a mistake. The bag stays open, same as
 * buying a skill, because this is posted from inside the dialog and the point
 * is to see the thing appear.
 *
 * A full bag is a DIFFERENT line from a short recipe, and is caught
 * separately: the price was paid in full, there is simply nowhere to put what
 * it would make, and "there is not enough" would be a false sentence about a
 * true state.
 */
class CompanionBuildController extends Controller
{
    /** Only the entries in `companion.bag` that actually have a recipe. */
    private function buildable(): array
    {
        return array_keys(array_filter(
            (array) config('companion.bag', []),
            static fn (array $item): bool => ($item['recipe'] ?? []) !== [],
        ));
    }

    public function store(Request $request, BuildItem $build): RedirectResponse
    {
        $validated = $request->validate([
            'item' => ['required', 'string', Rule::in($this->buildable())],
        ]);

        $item = (string) $validated['item'];
        $name = Companion::nameFor($request->user());
        $label = (string) (config("companion.bag.{$item}.label") ?? $item);

        try {
            $build->handle($request->user(), $item);
        } catch (CompanionCapacityException) {
            // Checked first: this subclass is also a CompanionEconomyException,
            // so the broader catch below would otherwise swallow it and say a
            // false "not enough" over a true "no room".
            return back()
                ->with(
                    CompanionController::SAID_KEY,
                    str_replace('{label}', $label, (string) config('companion.capacity.full')),
                )
                ->with(CompanionController::STAY_KEY, true);
        } catch (CompanionEconomyException) {
            return back()
                ->with(
                    CompanionController::SAID_KEY,
                    "There is not enough for a {$label} yet.",
                )
                ->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, "{$name} put together a {$label}.")
            ->with(CompanionController::STAY_KEY, true);
    }
}
