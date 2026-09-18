<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionNode;
use App\Models\User;
use InvalidArgumentException;

/**
 * Blob meets a node.
 *
 * This is the mechanic that lets a skill list exist without becoming a
 * checklist, and it is the part of F1 most worth getting right.
 *
 * All three nodes are visible in the scene from the start. Clicking one before
 * you have its skill DOES NOT FAIL AND DOES NOT SHOW A LOCK — Blob walks over,
 * turns it over and puts it down again, and that encounter is what puts the
 * skill in the list. So the list only ever contains skills whose subject you
 * have already met. It grows, and it never shows its own length: no totals, no
 * "2 of 6", no greyed rows, no prerequisites tree. (F1 §2)
 *
 * The row existing at zero IS the encounter. Nothing else records it, which is
 * why this writes even when there is nothing to take.
 *
 * Idempotent. Meeting a node again is the same encounter, not a second one, and
 * it never disturbs what is standing there.
 */
final readonly class MeetNode
{
    /**
     * @throws InvalidArgumentException when no such node is authored.
     */
    public function handle(User $user, string $node): CompanionNode
    {
        /** @var array<string, array<string, mixed>> $authored */
        $authored = (array) config('companion.nodes', []);

        if (! array_key_exists($node, $authored)) {
            throw new InvalidArgumentException("[{$node}] is not something Blob can walk over to.");
        }

        /** @var Companion $companion */
        $companion = $user->companion()->firstOrCreate([]);

        // firstOrCreate, never updateOrCreate: a node already holding six units
        // must still be holding six after being looked at again.
        return $companion->nodes()->firstOrCreate(['node' => $node]);
    }
}
