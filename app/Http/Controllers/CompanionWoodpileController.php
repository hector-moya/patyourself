<?php

namespace App\Http\Controllers;

use App\Actions\StackWood;
use App\Models\Companion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Stacking wood against the wall.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is to
 * watch the row leave the list.
 *
 * ONE DIRECTION, because there is only one. Nothing comes back out of the pile,
 * which is what separates it from the chest and is the whole of what this phase
 * adds to the economy.
 *
 * TWO 404s, and neither is a line in Blob's voice. A request naming something
 * the pile does not take is malformed rather than a state of the world; and a
 * request from an account with nothing built is naming a place that does not
 * exist, because the pile stands inside the shelter. The bag offers neither,
 * and there is no sentence for a gesture the screen never makes.
 */
class CompanionWoodpileController extends Controller
{
    public function store(Request $request, StackWood $stack): RedirectResponse
    {
        $validated = $request->validate([
            'item' => ['required', 'string'],
            'amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $item = (string) $validated['item'];

        /** @var list<string> $takes */
        $takes = (array) config('companion.woodpile.takes', []);

        abort_unless(in_array($item, $takes, true), Status::HTTP_NOT_FOUND);

        $user = $request->user();

        abort_if($user->companion()->value('shelter') === null, Status::HTTP_NOT_FOUND);

        $moved = $stack->handle($user, $item, $validated['amount'] ?? null);

        // Nothing was there. Said by saying nothing: a line about a stack that
        // did not exist would be the app narrating a press that did nothing.
        if ($moved === 0) {
            return back()->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                ['{name}', '{count}', '{label}'],
                [
                    Companion::nameFor($user),
                    (string) $moved,
                    (string) (config("companion.bag.{$item}.label") ?? $item),
                ],
                (string) config('companion.woodpile.stacked'),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
