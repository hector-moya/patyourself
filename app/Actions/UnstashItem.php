<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionStashItem;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob fetches something back out of the chest.
 *
 * BOUNDED BY WHAT BLOB CAN STILL CARRY, AND THE REMAINDER STAYS AT HOME. This
 * is {@see HarvestNode}'s promise seen from the other side, and it is
 * deliberately the same arithmetic rather than a second answer to one question:
 * a full bag refuses, anything else moves `min(stashed, room, wanted)`.
 *
 * That clamp is why the chest does not make containers pointless. The stash can
 * hold every plank the shelter's arc costs; the bag still decides how many can
 * be in hand at once, and a cabin is paid for out of the bag.
 *
 * The refusal reuses {@see CompanionEconomyException::bagIsFull()} rather than
 * adding a factory, because it is the same sentence about the same thing:
 * there is no room, and what you reached for stays where it was.
 * `noRoomFor()` stays what it is — a BUILD that priced out fine with nowhere to
 * put the result.
 *
 * Returns how many units moved. Zero is an ordinary answer: there was nothing
 * at home.
 */
final readonly class UnstashItem
{
    /**
     * @param  ?int  $wanted  How much to fetch, or null for as much as fits.
     * @return int How many units moved into the bag.
     *
     * @throws InvalidArgumentException when no such item is authored.
     * @throws CompanionEconomyException when the bag is full and something is
     *                                   standing at home.
     */
    public function handle(User $user, string $item, ?int $wanted = null): int
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can carry.");
        }

        return DB::transaction(function () use ($user, $item, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stack = $companion->stashItems()->where('item', $item)->first();

            // Nothing at home. A fact about the chest rather than a refusal —
            // the same answer an empty node gives.
            if (! $stack instanceof CompanionStashItem) {
                return 0;
            }

            // `room()` is computed from `items`. `$companion` was just resolved
            // fresh inside this transaction, so the relation would lazy-load on
            // first read regardless — this call is belt-and-braces, not
            // load-bearing, and it stays so this reads the same as
            // {@see HarvestNode} and {@see BuildItem}, which carry it too.
            $companion->load('items');

            $room = $companion->room();

            if ($room === 0) {
                throw CompanionEconomyException::bagIsFull($item);
            }

            $moved = min($stack->quantity, $room);

            if ($wanted !== null) {
                $moved = min($moved, max(1, $wanted));
            }

            // Deleted rather than zeroed, the same rule the bag follows: an
            // empty row would render as a line saying Blob has no planks at
            // home, which is not a thing worth saying.
            if ($moved === $stack->quantity) {
                $stack->delete();
            } else {
                $stack->decrement('quantity', $moved);
            }

            $companion->items()
                ->firstOrCreate(['item' => $item], ['quantity' => 0])
                ->increment('quantity', $moved);

            return $moved;
        });
    }
}
