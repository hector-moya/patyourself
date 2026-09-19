<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Database\Eloquent\Collection;

/**
 * An established account's cabin, handed back as what it was worth.
 *
 * `insights: 5` used to grant a cabin and now marks the floor at which one may
 * be BUILT. The threshold has not moved — it has changed what it does — and an
 * account that passed it must not simply lose what it had. So the cabin comes
 * apart into its materials, and putting it back up becomes the first thing
 * there is to do, which is the experience the whole arc was designed around.
 *
 * The materials go into the CLEARING, not into the bag, and the arithmetic
 * forces it: the whole arc costs more planks than any bag this game can build
 * will hold at once, so there is no way to hand them over directly. A heap
 * standing in the world is the rule "the world holds the overflow" doing
 * exactly what it was written for, out of machinery that already exists.
 *
 * SAFE TO RUN AGAIN. `salvaged_at` is why, and it is why that column exists: a
 * drained heap is deleted, so the heap's own presence cannot tell a converted
 * account from an unconverted one, and an unmarked account would be handed a
 * second cabin's worth of planks every time this ran.
 *
 * An account below the floor is left completely alone, including its ABSENCE
 * of a companion row: the row is the chosen half of Blob, and nothing has been
 * chosen. The insight count is therefore checked before anything is written.
 */
final readonly class SalvageTheCabin
{
    public function __construct(private CompanionResolver $resolver) {}

    /**
     * @return int How many companions were given a heap.
     */
    public function handle(): int
    {
        $floor = (int) config('companion.shelter.cabin.insights', 5);
        $node = (string) config('companion.salvage.node', 'salvage');
        $stock = (int) config('companion.salvage.stock', 0);

        $given = 0;

        // Chunked rather than loaded whole: this runs once, at deploy, over
        // every account there is.
        User::query()->chunkById(100, function (Collection $users) use ($floor, $node, $stock, &$given): void {
            foreach ($users as $user) {
                // Checked BEFORE anything is written, so an account below the
                // floor does not even gain a companion row.
                if (count($this->resolver->insightMoments($user)) < $floor) {
                    continue;
                }

                /** @var Companion $companion */
                $companion = $user->companion()->firstOrCreate([]);

                if ($companion->salvaged_at !== null) {
                    continue;
                }

                // firstOrCreate, never updateOrCreate: a heap already standing
                // is left exactly as it is rather than topped back up.
                $companion->nodes()->firstOrCreate(['node' => $node], ['available' => $stock]);

                // forceFill, because `salvaged_at` is deliberately not
                // fillable — it is written once, here, and by nothing a
                // request can reach.
                $companion->forceFill(['salvaged_at' => now()])->save();

                $given++;
            }
        });

        return $given;
    }
}
