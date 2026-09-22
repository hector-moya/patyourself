<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob puts something down at home, in the chest.
 *
 * NOTHING IS DESTROYED AND NOTHING IS SPENT: this is one stack in two places
 * over time, and it can be fetched back. That is what separates it from
 * {@see DropItem}, which is the only path in this feature that destroys, and it
 * is why this needs no confirmation and no refusal of its own.
 *
 * THE STASH IS UNCAPPED, so there is no room check here. The world is uncapped
 * everywhere else — node stock has no ceiling and nothing expires — and a limit
 * here would be the first place the world refuses to hold something. What the
 * stash does NOT do is raise what Blob can carry: capacity still caps the bag,
 * and `Companion::held()` still counts only `items`.
 *
 * Only CARRIED categories, the same rule {@see DropItem} enforces and for the
 * same reasons: a tool is on the belt and occupies no room, and a container
 * stashed would silently lower capacity — regression wearing a different hat.
 *
 * Returns how many units moved, so the caller can say so in the app's own
 * voice. Zero is an ordinary answer: there was nothing in hand.
 */
final readonly class StashItem
{
    /**
     * @param  ?int  $wanted  How much to put down, or null for the whole stack.
     *                        Putting something down is not a measured act the
     *                        way taking from a node is — but the bag offers the
     *                        amount anyway, because parking two of three planks
     *                        and sawing on is the gesture this whole phase
     *                        exists for.
     * @return int How many units moved into the chest.
     *
     * @throws InvalidArgumentException when no such item is authored, or when
     *                                  its category is not one Blob carries.
     */
    public function handle(User $user, string $item, ?int $wanted = null): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        if (! in_array((string) ($catalogue[$item]['category'] ?? ''), $carried, true)) {
            throw new InvalidArgumentException("[{$item}] is not carried, so there is nothing to put down.");
        }

        return DB::transaction(function () use ($user, $item, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->items()->where('item', $item)->first();

            if (! $stack instanceof CompanionItem) {
                return 0;
            }

            // Floored at one rather than clamped to zero, the same as a
            // harvest: a caller asking for nothing has asked a question this
            // action has no answer for, and moving nothing while reporting
            // success would say the bag was empty when it was not.
            $moved = $wanted === null
                ? $stack->quantity
                : min($stack->quantity, max(1, $wanted));

            // Deleted rather than zeroed, the same rule the bag already
            // follows: an empty row would render as a line saying Blob is
            // carrying no planks, which is not a thing worth saying.
            if ($moved === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->decrement('quantity', $moved);
            }

            $companion->stashItems()
                ->firstOrCreate(['item' => $item], ['quantity' => 0])
                ->increment('quantity', $moved);

            return $moved;
        });
    }
}
