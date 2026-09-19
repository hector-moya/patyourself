<?php

namespace App\Listeners;

use App\Events\ActionLogged;
use App\Models\Companion;
use App\Models\CompanionSkill;

/**
 * The record stocks the world.
 *
 * Each outcome recorded adds one unit to each node whose skill Blob has
 * learned. Not a clock and not a cron: nothing grows while you are away, and
 * nothing expires either — the world holds whatever you could not carry, for
 * as long as it takes you to come back for it. (F1 §4)
 *
 * BEING A LISTENER IS THE MECHANISM, not an implementation detail. XP is
 * retroactive over the whole record on purpose — the record is real and it
 * counts — but the world is deliberately not, and this is what makes that true:
 * an established account that learns a skill today has no past events left to
 * fire, so it arrives to an empty clearing rather than one holding a unit for
 * every outcome it ever logged. There is no `learned_at` comparison here for
 * the same reason; `logged_at` is always the moment of the write, so a log
 * earlier than the skill it stocks cannot happen, and a guard over a state the
 * app cannot reach is a claim nobody can check.
 *
 * Not queued. It is two small writes inside a request that has already written,
 * and a queued listener would re-fetch a companion row that may not exist yet.
 */
class StockCompanionNodes
{
    public function handle(ActionLogged $event): void
    {
        // Queried rather than read off `$event->user->companion`. Eloquent
        // caches a lazily-loaded relation ON THE MODEL INSTANCE, including when
        // it resolves to null — so a user object that was asked for its
        // companion before the row existed keeps answering null for the rest of
        // the request, and the stocking silently does nothing. That is reachable
        // the moment anything creates the row and logs an outcome in one
        // request, which is a request this app will eventually have.
        $companion = Companion::query()
            ->where('user_id', $event->user->id)
            ->first();

        // No companion row means nothing has been chosen yet, so there is no
        // clearing to stock. Logging alone never creates one — Blob is given by
        // the record, but the world is entered by choosing something.
        if (! $companion instanceof Companion) {
            return;
        }

        /** @var array<string, array{skill?: string, yields: string, label: string}> $nodes */
        $nodes = (array) config('companion.nodes', []);

        $learned = $companion->skills()
            ->get()
            ->keyBy(static fn (CompanionSkill $skill): string => $skill->name);

        foreach ($nodes as $name => $node) {
            $skill = (string) ($node['skill'] ?? '');

            // A node that names no skill is a heap rather than part of the
            // world: something put it there and nothing restocks it. Skipping
            // it is not an optimisation — stocking it would rebuild the thing
            // it is the remains of, one outcome at a time.
            if ($skill === '' || ! $learned->has($skill)) {
                continue;
            }

            // updateOrCreate rather than increment-or-insert: the row may
            // already exist at zero from the encounter that revealed the skill
            // (see MeetNode), and it may not exist at all if the skill was
            // bought without the node ever being clicked.
            $companion->nodes()
                ->updateOrCreate(['node' => $name], [])
                ->increment('available');
        }
    }
}
