<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionNode;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob picks up what is standing at a node.
 *
 * Bounded by what Blob can still carry, and THE REMAINDER STAYS STANDING.
 * Nothing is ever destroyed — that is the invariant this whole action exists to
 * respect, and it is what makes a full bag a reason to build the next container
 * rather than a punishment for not having built one yet. (F1 §4)
 *
 * Returns how many units moved, so the caller can say so in the app's own
 * voice. Zero is an ordinary answer — an empty node is not a refusal, there was
 * simply nothing there.
 *
 * A node that names no SKILL is a heap rather than part of the world: it needs
 * nothing learned, nothing restocks it, and it is removed once it is drained.
 */
final readonly class HarvestNode
{
    /**
     * @param  ?int  $wanted  How much to take, or null to take what fits.
     *                        Absent is the clearing's own gesture: one click
     *                        fills the bag. Naming an amount is the deliberate
     *                        act, and it is what stops a node force-filling the
     *                        bag with one material and leaving nothing buildable.
     * @return int How many units moved into the bag.
     *
     * @throws InvalidArgumentException when no such node is authored.
     * @throws CompanionEconomyException when the skill is unlearned, the tool
     *                                   is not held, or the bag is full while
     *                                   stock is standing.
     */
    public function handle(User $user, string $node, ?int $wanted = null): int
    {
        /** @var array<string, array{skill?: string, yields: string, label: string, tool?: string}> $authored */
        $authored = (array) config('companion.nodes', []);

        if (! array_key_exists($node, $authored)) {
            throw new InvalidArgumentException("[{$node}] is not something Blob can gather from.");
        }

        $skill = (string) ($authored[$node]['skill'] ?? '');
        $yields = (string) $authored[$node]['yields'];
        $tool = (string) ($authored[$node]['tool'] ?? '');

        return DB::transaction(function () use ($user, $node, $skill, $yields, $tool, $wanted): int {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            // A node that names no skill needs none: a heap is not a thing you
            // learn to use, it is a thing somebody put there.
            if ($skill !== '' && $companion->skills()->where('name', $skill)->doesntExist()) {
                throw CompanionEconomyException::skillNotLearned($node, $skill);
            }

            // The skill first, then the tool. A node whose skill has not been
            // bought is a node whose tool is not yet the problem, and refusing
            // for the further of the two reasons would send the reader past
            // the nearer one.
            //
            // Absent is "needs none": every node F1 authored has no tool key
            // and must go on behaving exactly as it always has.
            if ($tool !== '' && $companion->items()->where('item', $tool)->doesntExist()) {
                throw CompanionEconomyException::toolNotHeld($node, $tool);
            }

            $standing = $companion->nodes()->where('node', $node)->first();

            // Never met, or met and empty. Both are "there is nothing here",
            // which is a fact about the world rather than a refusal.
            if (! $standing instanceof CompanionNode || $standing->available === 0) {
                return 0;
            }

            // `items` may be stale on a companion the caller has been holding,
            // and capacity is computed from it.
            $companion->load('items');

            $room = $companion->room();

            if ($room === 0) {
                throw CompanionEconomyException::bagIsFull($node);
            }

            // Floored at one rather than clamped to zero: a caller asking for
            // nothing has asked a question this action has no answer for, and
            // taking nothing while reporting success would say "the reeds are
            // empty" about a node that is not. The route validates `min:1`, so
            // this is the second of the two.
            $moved = min($standing->available, $room);

            if ($wanted !== null) {
                $moved = min($moved, max(1, $wanted));
            }

            $standing->decrement('available', $moved);

            // A drained heap is removed rather than left standing as an empty
            // label forever. Nothing is taken by it — there is nothing left in
            // it to take — and it is the only delete path in this feature that
            // is not a stack spent on something. A node of the world is never
            // removed, however empty it gets: the record refills it.
            if ($skill === '' && $standing->fresh()->available === 0) {
                $standing->delete();
            }

            $companion->items()
                ->firstOrCreate(['item' => $yields], ['quantity' => 0])
                ->increment('quantity', $moved);

            return $moved;
        });
    }
}
