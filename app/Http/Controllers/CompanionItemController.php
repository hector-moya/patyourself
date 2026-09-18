<?php

namespace App\Http\Controllers;

use App\Actions\DropItem;
use App\Models\Companion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Tipping a stack out of the bag.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is
 * to watch the room appear.
 *
 * NO CONFIRMATION. Spec §5 rules it out and the reason is the copy rule rather
 * than convenience — an "are you sure" is the app having an opinion about a
 * choice that is the player's, and every other refusal in this feature is
 * careful not to. What comes back describes what Blob did and stops there.
 *
 * Only carried categories are routable at all. A tool or a container reaching
 * this is a malformed request rather than a state of the world, so it is a 404
 * rather than a line in Blob's voice: there is no sentence to say about a
 * gesture the screen never offers.
 */
class CompanionItemController extends Controller
{
    /** Only the entries in `companion.bag` a bag can actually be relieved of. */
    private function droppable(): array
    {
        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        return array_keys(array_filter(
            (array) config('companion.bag', []),
            static fn (array $item): bool => in_array((string) ($item['category'] ?? ''), $carried, true),
        ));
    }

    public function destroy(Request $request, DropItem $drop, string $item): RedirectResponse
    {
        abort_unless(in_array($item, $this->droppable(), true), Status::HTTP_NOT_FOUND);

        $user = $request->user();
        $label = (string) (config("companion.bag.{$item}.label") ?? $item);

        $dropped = $drop->handle($user, $item);

        // Nothing was there. Said by saying nothing: a line about a stack that
        // did not exist would be the app narrating a press that did nothing.
        if ($dropped === 0) {
            return back()->with(CompanionController::STAY_KEY, true);
        }

        return back()
            ->with(CompanionController::SAID_KEY, str_replace(
                ['{name}', '{label}'],
                [Companion::nameFor($user), $label],
                (string) config('companion.drop'),
            ))
            ->with(CompanionController::STAY_KEY, true);
    }
}
