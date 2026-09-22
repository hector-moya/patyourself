<?php

namespace App\Services\Companion;

use RuntimeException;

/**
 * A refusal the economy makes.
 *
 * Eight factories now, not the three this once was, but the shape held: something
 * was asked for that the current state cannot pay for. NONE OF THEM DESTROYS
 * ANYTHING, and none of them is a failure on the user's part — a full bag is a
 * reason to build the next container, not a mistake, and the words say so
 * rather than scolding.
 *
 * One exception to "cannot pay for": {@see noRoomFor()} alone is a *capacity*
 * refusal rather than a shortage — the price was paid, there is nowhere to put
 * what it bought — and it returns the distinct {@see CompanionCapacityException}
 * subclass so a caller can tell the two apart without parsing a message.
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

    /**
     * A build that would not fit.
     *
     * A second factory rather than a reworded `bagIsFull()`: the two say
     * different sentences about different things — one is about what stays
     * standing at a node, this is about a thing that cannot be put down — and
     * rewording the other would edit a message the clearing already ships.
     *
     * Returns {@see CompanionCapacityException}, not a plain instance of this
     * class: the caller's catch block needs to tell "no room" apart from every
     * other refusal here, because it is the one case where the answer is not
     * "not enough."
     */
    public static function noRoomFor(string $thing): self
    {
        return new CompanionCapacityException("There is no room left in the bag for [{$thing}].");
    }

    /**
     * Something was asked for that the screen never offered.
     *
     * The shelter's own refusal, and the one factory here whose message is not
     * really for a user: a stage whose predecessor is missing, or whose floor
     * the record has not reached, is ABSENT from the bag rather than listed
     * and disabled — so reaching this means a request the screen does not
     * make. It stays a refusal rather than an abort because it is still a
     * statement about the current state, and because the one thing it must not
     * do is take anything on the way out.
     */
    public static function notOffered(string $thing): self
    {
        return new self("[{$thing}] is not something Blob can put up yet.");
    }

    /**
     * A structure that is already standing.
     *
     * Deliberately not worded as a shortage, because it is not one: nothing
     * that could be gathered would change the answer. A structure stands once,
     * so the screen stops offering a second the moment the first goes up —
     * {@see CompanionBag::recipes()} drops it from the list — and reaching this
     * means the read and the action have drifted apart.
     */
    public static function alreadyStanding(string $thing): self
    {
        return new self("[{$thing}] is already standing; there is only ever one.");
    }
}
