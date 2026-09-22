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
 * Building needs no skill, and it never will. Assembling by hand is what hands
 * are for — but a hand may need the right thing in it, which is why a recipe
 * can name a `tool`. That is the whole of F2's rule from this side: a node
 * gates on skill and tool, a recipe gates on tool alone.
 *
 * A tool is USED, never used up. It is not an ingredient and nothing consumes
 * it; the check is that it is held, and the bag is unchanged by it afterwards.
 *
 * A shortfall names EVERYTHING that is missing rather than the first thing, so
 * a recipe short on two ingredients takes one look rather than two attempts.
 */
final readonly class BuildItem
{
    /**
     * @throws InvalidArgumentException when no such item is authored, or it has
     *                                  no recipe and so is not a thing to build.
     * @throws CompanionEconomyException when the tool is not held or the
     *                                   materials are short.
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

        $tool = (string) ($catalogue[$item]['tool'] ?? '');
        $category = (string) ($catalogue[$item]['category'] ?? '');

        // `makes`, not `yields`: `nodes.*.yields` already holds the NAME of a
        // material, and the same word holding a count in the adjacent config
        // block would be two types under one name.
        //
        // Floored at one: a recipe that makes nothing is a config mistake, and
        // silently making nothing is worse than making the default.
        $makes = max(1, (int) ($catalogue[$item]['makes'] ?? 1));

        return DB::transaction(function () use ($user, $item, $recipe, $tool, $category, $makes): CompanionItem {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            // A structure stands ONCE. Without this the `firstOrCreate` and
            // `increment` at the end of this method would quietly stack a
            // second chest onto the first, and there is no second stash for it
            // to be.
            //
            // Checked before the tool and before the materials because it is
            // not a shortage: nothing Blob could go and gather would change the
            // answer, so sending the reader past it to a price would be the
            // wrong sentence.
            if ($category === 'structure'
                && $companion->items()->where('item', $item)->exists()) {
                throw CompanionEconomyException::alreadyStanding($item);
            }

            // A recipe gates on a tool and NEVER on a skill: assembling by hand
            // is what hands are for, and what a hand needs is the right thing
            // in it. Checked before the materials because the tool is the
            // harder of the two to come by.
            if ($tool !== '' && $companion->items()->where('item', $tool)->doesntExist()) {
                throw CompanionEconomyException::toolNotHeld($item, $tool);
            }

            $missing = $companion->shortfallFor($recipe);

            if ($missing !== []) {
                throw CompanionEconomyException::missingMaterials($item, $missing);
            }

            // Capacity last, because you cannot overflow with materials you do
            // not have — a short recipe should say what it is short of rather
            // than complain about room.
            //
            // Only CARRIED categories count, on both sides. A recipe that makes
            // a tool or a container is always net-negative and can never
            // refuse here; one that makes three carried things out of one is
            // net +2, and that is the case this exists for. The arithmetic
            // itself lives on the model — {@see Companion::wouldFit()} — so
            // CompanionBag's read of "will this build" can never drift from
            // what this action actually enforces.
            $companion->load('items');

            if (! $companion->wouldFit($item, $recipe, $makes)) {
                throw CompanionEconomyException::noRoomFor($item);
            }

            $companion->spend($recipe);

            $built = $companion->items()->firstOrCreate(['item' => $item], ['quantity' => 0]);

            $built->increment('quantity', $makes);

            return $built;
        });
    }
}
