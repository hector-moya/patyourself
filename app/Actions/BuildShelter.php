<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use App\Services\Companion\CompanionResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Blob puts up one stage of the shelter.
 *
 * The arc is lean-to → hut → cabin, and the stages REPLACE one another: two
 * are never standing at once, because building the hut is the lean-to becoming
 * a hut and the lean-to's planks are in it. What is built is therefore one
 * stored value, and this is its only writer.
 *
 * IT ONLY EVER ADVANCES. Every stage requires the key immediately before it in
 * `config('companion.shelter')` to be what is currently standing, which makes
 * "already built" and "built out of order" the same refusal, and makes going
 * backwards unexpressible rather than merely forbidden.
 *
 * NO SKILL AND NO TOOL, and that is F2's rule rather than an omission: a recipe
 * gates on a tool and never on a skill, and planks already carry the handsaw's
 * gate upstream. A second gate here would tax the same work twice.
 *
 * NO CAPACITY CHECK, and that is not an omission either: a structure is not
 * carried, so putting one up is always net-negative on what the bag holds and
 * can never overflow it. {@see BuildItem} needs the check because sawing makes
 * three carried things out of one; this cannot.
 *
 * The cabin additionally names a FLOOR of five insights. That number has not
 * moved since E1 — it has stopped granting the cabin and started being the
 * point at which the cabin may be built, which is spec §2's first overturn and
 * the reason the whole arc exists: people value what they built.
 */
final readonly class BuildShelter
{
    public function __construct(private CompanionResolver $resolver) {}

    /**
     * @return string The stage now standing.
     *
     * @throws InvalidArgumentException when no such stage is authored.
     * @throws CompanionEconomyException when the predecessor is not standing,
     *                                   the floor is not reached, or the
     *                                   materials are short.
     */
    public function handle(User $user, string $stage): string
    {
        /** @var array<string, array<string, mixed>> $stages */
        $stages = (array) config('companion.shelter', []);

        if (! array_key_exists($stage, $stages)) {
            throw new InvalidArgumentException("[{$stage}] is not something Blob can put up.");
        }

        $order = array_keys($stages);
        $at = (int) array_search($stage, $order, true);

        // The key before it, or null for the first. The ORDER of the config is
        // the arc; a back-pointer on each entry would be a second copy of it.
        $predecessor = $at === 0 ? null : $order[$at - 1];

        /** @var array<string, int> $recipe */
        $recipe = (array) ($stages[$stage]['recipe'] ?? []);
        $floor = (int) ($stages[$stage]['insights'] ?? 0);

        return DB::transaction(function () use ($user, $stage, $predecessor, $recipe, $floor): string {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            if ($companion->shelter !== $predecessor) {
                throw CompanionEconomyException::notOffered($stage);
            }

            // Counted only when a stage actually names a floor, so nobody pays
            // four queries for a gate that does not apply to them.
            if ($floor > 0 && count($this->resolver->insightMoments($user)) < $floor) {
                throw CompanionEconomyException::notOffered($stage);
            }

            $missing = $companion->shortfallFor($recipe);

            if ($missing !== []) {
                throw CompanionEconomyException::missingMaterials($stage, $missing);
            }

            $companion->spend($recipe);

            $companion->update(['shelter' => $stage]);

            return $stage;
        });
    }
}
