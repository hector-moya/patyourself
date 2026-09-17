<?php

namespace App\Services\Companion;

use RuntimeException;

/**
 * A refusal the economy makes.
 *
 * All three cases are the same shape: something was asked for that the current
 * state cannot pay for. NONE OF THEM DESTROYS ANYTHING, and none of them is a
 * failure on the user's part — a full bag is a reason to build the next
 * container, not a mistake, and the words say so rather than scolding.
 *
 * Callers on a screen should turn these into a flash message rather than an
 * error page: a short balance and a full bag are ordinary states of the world.
 */
class CompanionEconomyException extends RuntimeException
{
    public static function cannotAfford(string $thing, int $price, int $balance): self
    {
        return new self("[{$thing}] costs {$price} xp and the balance is {$balance}.");
    }

    public static function bagIsFull(string $node): self
    {
        return new self("There is no room left in the bag for anything from [{$node}].");
    }

    /**
     * @param  array<string, int>  $missing  Item name to how many more are needed.
     */
    public static function missingMaterials(string $thing, array $missing): self
    {
        $shortfall = implode(', ', array_map(
            static fn (string $item, int $count): string => "{$count} {$item}",
            array_keys($missing),
            array_values($missing),
        ));

        return new self("[{$thing}] still needs {$shortfall}.");
    }

    public static function skillNotLearned(string $node, string $skill): self
    {
        return new self("Blob has not learned [{$skill}], so nothing can be taken from [{$node}] yet.");
    }

    /**
     * Something was asked for that needs a thing Blob is not carrying.
     *
     * Takes a `$thing` rather than a node, because both halves of F2's rule
     * raise this: a node that needs an axe and a recipe that needs a handsaw
     * are the same refusal about the same kind of object.
     */
    public static function toolNotHeld(string $thing, string $tool): self
    {
        return new self("[{$thing}] needs [{$tool}], which Blob is not carrying.");
    }
}
