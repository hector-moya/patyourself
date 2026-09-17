<?php

namespace App\Actions;

use App\Models\Companion;
use App\Models\CompanionSkill;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use App\Services\Companion\CompanionWallet;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Buys one skill.
 *
 * This is the one consequential choice in the whole feature, and it is safe
 * because it is about the fiction and never the therapy: you choose what Blob
 * learns, you never choose whether to log. Two identical records can produce
 * different Blobs, permanently, because of what was bought first.
 *
 * THE ONLY WRITER OF `companions.xp_spent`. Everything about the balance is
 * derived from the record and this one column; a second writer would be a
 * second opinion about what has been spent.
 *
 * Learning a skill Blob already has is a no-op rather than an error. A
 * double-submitted click is not a user mistake, and the unique index on
 * (companion_id, name) means the alternative is a QueryException reaching a
 * controller.
 */
final readonly class LearnSkill
{
    public function __construct(private CompanionWallet $wallet) {}

    /**
     * @throws InvalidArgumentException when no such skill is authored.
     * @throws CompanionEconomyException when the balance is short.
     */
    public function handle(User $user, string $skill): CompanionSkill
    {
        /** @var array<string, array{price: int, node: string, label: string}> $authored */
        $authored = (array) config('companion.skills', []);

        if (! array_key_exists($skill, $authored)) {
            throw new InvalidArgumentException("[{$skill}] is not a skill Blob can learn.");
        }

        $price = (int) ($authored[$skill]['price'] ?? 0);

        return DB::transaction(function () use ($user, $skill, $price): CompanionSkill {
            /** @var Companion $companion */
            $companion = $user->companion()->firstOrCreate([]);

            $already = $companion->skills()->where('name', $skill)->first();

            if ($already instanceof CompanionSkill) {
                return $already;
            }

            $balance = $this->wallet->balanceFor($user);

            if ($balance < $price) {
                throw CompanionEconomyException::cannotAfford($skill, $price, $balance);
            }

            $companion->increment('xp_spent', $price);

            return $companion->skills()->create([
                'name' => $skill,
                // The epoch for this skill's node: stock accrues only from
                // here, so an established record starts at an empty clearing.
                'learned_at' => Date::now(),
            ]);
        });
    }
}
