<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob tips a stack out of the bag, because the player said so.
 *
 * THE ONLY PATH IN THIS FEATURE THAT DESTROYS ANYTHING OUTSIDE A RECIPE, and
 * it is reached only by a person pressing a button. What is dropped does not
 * go back to the node it came from, does not go into the world, and does not
 * come back. Returning it was considered and rejected: a lossless bag is a
 * scratchpad, and it would take the weight out of every decision to pick
 * something up.
 *
 * Only CARRIED categories may be dropped. A tool is on the belt and occupies
 * no room, so tipping one out gains nothing and loses something permanent; a
 * container would lower capacity, which is the regression the whole feature
 * refuses. The overturn this action rests on was argued on the grounds that
 * the player SPENDS, and a destruction that buys nothing is not spending.
 *
 * Whole stacks only. A quantity would be a second way to say the same thing —
 * a harvest that takes less is how you avoid carrying more than you want, and
 * that choice belongs at the moment of picking up rather than of putting down.
 *
 * Returns how many units went, so the caller can say so in the app's own
 * voice. Zero is an ordinary answer: there was nothing there.
 */
final readonly class DropItem
{
    /**
     * @return int How many units were destroyed.
     *
     * @throws InvalidArgumentException when no such item is authored, or when
     *                                  its category is not one Blob carries.
     */
    public function handle(User $user, string $item): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        if (! in_array((string) ($catalogue[$item]['category'] ?? ''), $carried, true)) {
            throw new InvalidArgumentException("[{$item}] is not carried, so there is nothing to tip out.");
        }

        return DB::transaction(function () use ($user, $item): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->items()->where('item', $item)->first();

            if (! $stack instanceof CompanionItem) {
                return 0;
            }

            $quantity = $stack->quantity;

            // Deleted rather than zeroed, the same rule BuildItem already
            // follows for a stack spent to nothing: an empty row would render
            // as a line saying Blob is carrying no timber, which is not a
            // thing worth saying.
            $stack->delete();

            return $quantity;
        });
    }
}
