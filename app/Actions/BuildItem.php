<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionItem;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob builds something out of what it is carrying.
 *
 * MATERIALS TRANSFORM; THEY ARE NEVER TAKEN. Spending four fibre on a basket is
 * not losing four fibre, it is the basket having four fibre in it. That is what
 * lets this feature have a consumption mechanic at all without breaking the
 * rule that nothing about Blob ever regresses.
 *
 * Building needs no skill. Assembling by hand is what hands are for, and it is
 * what keeps F1 at two skills rather than four.
 *
 * A shortfall names EVERYTHING that is missing rather than the first thing, so
 * a recipe short on two ingredients takes one look rather than two attempts.
 */
final readonly class BuildItem
{
    /**
     * @throws InvalidArgumentException when no such item is authored, or it has
     *                                  no recipe and so is not a thing to build.
     * @throws CompanionEconomyException when the materials are short.
     */
    public function handle(User $user, string $item): CompanionItem
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        if (! array_key_exists($item, $catalogue)) {
            throw new InvalidArgumentException("[{$item}] is not something Blob can build.");
        }

        /** @var array<string, int> $recipe */
        $recipe = (array) ($catalogue[$item]['recipe'] ?? []);

        if ($recipe === []) {
            throw new InvalidArgumentException("[{$item}] has no recipe; it is gathered, not built.");
        }

        return DB::transaction(function () use ($user, $item, $recipe): CompanionItem {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $stacks = $companion->items()
                ->whereIn('item', array_keys($recipe))
                ->get()
                ->keyBy(static fn (CompanionItem $stack): string => $stack->item);

            $missing = [];

            foreach ($recipe as $ingredient => $needed) {
                $have = (int) ($stacks->get($ingredient)?->quantity ?? 0);

                if ($have < $needed) {
                    $missing[$ingredient] = $needed - $have;
                }
            }

            if ($missing !== []) {
                throw CompanionEconomyException::missingMaterials($item, $missing);
            }

            foreach ($recipe as $ingredient => $needed) {
                /** @var CompanionItem $stack */
                $stack = $stacks->get($ingredient);

                // A stack spent to nothing is removed rather than left at zero:
                // an empty row would render as a line in the bag saying Blob is
                // carrying no fibre, which is not a thing worth saying.
                if ($stack->quantity === $needed) {
                    $stack->delete();

                    continue;
                }

                $stack->decrement('quantity', $needed);
            }

            $built = $companion->items()->firstOrCreate(['item' => $item], ['quantity' => 0]);

            $built->increment('quantity');

            return $built;
        });
    }
}
