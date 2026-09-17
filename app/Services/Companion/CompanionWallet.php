<?php

namespace App\Services\Companion;

use App\Models\Companion;
use App\Models\User;

/**
 * What the record has paid, what has been spent, and what is left.
 *
 * The property worth protecting from the pre-F1 design is that nothing can
 * silently drift, and it survives here because only the spending is stored:
 *
 *     balance = max(0, earned − spent)
 *               ^^^^^^        ^^^^^
 *               derived       companions.xp_spent
 *               from the
 *               record
 *
 * Earned is recomputed from the record on every read, exactly as the ladder's
 * counts are. Deleting history lowers it and therefore the balance — but skills
 * already learned are stored and are never revoked, and the clamp stops the
 * balance going negative. The worst case is being unable to buy until more is
 * recorded, which takes nothing away. This replaces the `companion_high_water`
 * idea floated in {@see CompanionResolver}'s docblock; it is strictly better,
 * because there is no watermark to maintain.
 *
 * Reads the record THROUGH the resolver rather than querying it again: two
 * readers deriving the same two streams separately is how they come to
 * disagree about what happened.
 *
 * NOTHING HERE MAY EVER BRANCH ON AN OUTCOME. A `failed` outcome pays exactly
 * what a `completed` one pays; see `config('companion.xp')` for why.
 */
final readonly class CompanionWallet
{
    public function __construct(private CompanionResolver $resolver) {}

    /** What the record has paid, over the whole of it. */
    public function earnedFor(User $user): int
    {
        return $this->outcomeXp($user) + $this->insightXp($user);
    }

    /**
     * What has been spent. Zero before there is anything to spend it with — a
     * user with no companion row has made no choices, not an error.
     */
    public function spentFor(User $user): int
    {
        return (int) Companion::query()
            ->where('user_id', $user->id)
            ->value('xp_spent');
    }

    /** What is left. Clamped, so a deleted record can never owe anything. */
    public function balanceFor(User $user): int
    {
        return max(0, $this->earnedFor($user) - $this->spentFor($user));
    }

    /**
     * Outcomes, tapered within each day.
     *
     * The day is the USER'S own, not the server's: "the first outcome of the
     * day" is a claim about when they sat down, and someone in Auckland logging
     * at nine in the evening has not started a second day.
     *
     * Grouped by `logged_at` rather than by the occasion, which the resolver
     * already dates that way. The consequence is deliberate: a catch-up session
     * logging seven days at once tapers as one day, because the taper rewards
     * showing up and you showed up once.
     */
    private function outcomeXp(User $user): int
    {
        /** @var list<int> $taper */
        $taper = array_values(array_map(
            static fn ($value): int => (int) $value,
            (array) config('companion.xp.outcome', [1]),
        ));

        // An empty taper is a config mistake, not a reason to throw at someone
        // reading their own record. It simply pays nothing.
        if ($taper === []) {
            return 0;
        }

        $floor = count($taper) - 1;
        $timezone = $user->timezone ?? (string) config('app.timezone');

        $perDay = [];

        foreach ($this->resolver->logMoments($user) as $moment) {
            $day = $moment->setTimezone($timezone)->toDateString();

            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        $total = 0;

        foreach ($perDay as $count) {
            for ($index = 0; $index < $count; $index++) {
                // The last value is the floor and repeats forever: nothing
                // recorded ever goes unpaid.
                $total += $taper[min($index, $floor)];
            }
        }

        return $total;
    }

    /**
     * Insights, at a flat rate per kind and OUTSIDE the taper — they are rarer
     * by nature, and rate-limiting them would be double-counting.
     *
     * A kind config does not price pays nothing rather than throwing: an
     * unpriced source is a config omission, and reading your own record is not
     * where that should be discovered.
     */
    private function insightXp(User $user): int
    {
        /** @var array<string, int> $rates */
        $rates = (array) config('companion.xp.insight', []);

        $total = 0;

        foreach ($this->resolver->insightMoments($user) as $moment) {
            $total += (int) ($rates[$moment['kind']] ?? 0);
        }

        return $total;
    }
}
