<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob stacks wood against the wall, and it does not come back.
 *
 * THIS IS THE ONE-WAY MOVE. Material entering the pile is SPENT, exactly as
 * fibre spent on a basket is spent: the player asked, the price was named, and
 * nothing was taken on their behalf. {@see DropItem} remains the only path that
 * DESTROYS — the difference is that this one leaves something standing where
 * the material went.
 *
 * THE PILE IS UNCAPPED, for the same reason the chest is: the world is uncapped
 * everywhere else in this feature, and a limit here would be the first place
 * the world refuses to hold something. There is no room check in either
 * direction, because there is no other direction.
 *
 * Only what `companion.woodpile.takes` names. That is not a second copy of
 * `capacity.carried`: that list says what the bag may hold, this one says what
 * this object accepts, which is what a recipe has always said about its own
 * ingredients.
 *
 * THE ONLY WRITER OF `companions.woodpile`, and it only ever increments.
 *
 * A SHELTER IS NOT CHECKED HERE. The pile stands inside one, so the screen
 * never offers this without one and the controller answers a direct request
 * with a 404 — a state of the request rather than a state of the world. Nothing
 * in this action depends on where the pile is.
 *
 * Nothing here reads the record. The pile is a choice, and whether Blob sleeps
 * stays a function of the clock alone; only WHERE it sleeps follows from what
 * was built.
 */
final readonly class StackWood
{
    /**
     * @param  ?int  $wanted  How much to stack, or null for the whole stack.
     * @return int How many units went into the pile.
     *
     * @throws InvalidArgumentException when no such item is authored, or when
     *                                  the pile does not take it.
     */
    public function handle(User $user, string $item, ?int $wanted = null): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        /** @var list<string> $takes */
        $takes = (array) config('companion.woodpile.takes', []);

        if (! in_array($item, $takes, true)) {
            throw new InvalidArgumentException("The pile does not take [{$item}].");
        }

        return DB::transaction(function () use ($user, $item, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->items()->where('item', $item)->first();

            if (! $stack instanceof CompanionItem) {
                return 0;
            }

            // Floored at one rather than clamped to zero, the same as a harvest
            // and the same as putting something in the chest: a caller asking
            // for nothing has asked a question this action has no answer for.
            $moved = $wanted === null
                ? $stack->quantity
                : min($stack->quantity, max(1, $wanted));

            // Deleted rather than zeroed, the rule the bag already follows: an
            // empty row would render as a line saying Blob is carrying no
            // planks, which is not a thing worth saying.
            if ($moved === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->decrement('quantity', $moved);
            }

            $companion->increment('woodpile', $moved);

            return $moved;
        });
    }
}
