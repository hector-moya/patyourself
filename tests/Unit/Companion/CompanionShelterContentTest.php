<?php

namespace Tests\Unit\Companion;

use PHPUnit\Framework\TestCase;

/**
 * F3's content, checked for the ways config can lie.
 *
 * A pure read of `config/companion.php`, in the same style as
 * {@see CompanionContentTest} and {@see CompanionLadderTest}: no database, no
 * container, just the authored data and the invariants anything reading it
 * depends on.
 */
class CompanionShelterContentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return require __DIR__.'/../../../config/companion.php';
    }

    public function test_every_stage_is_priced_in_things_that_exist(): void
    {
        $config = $this->config();

        $this->assertNotEmpty($config['shelter']);

        foreach ($config['shelter'] as $stage => $entry) {
            $this->assertNotSame('', trim((string) $entry['label']), "{$stage} has no label");
            $this->assertNotEmpty($entry['recipe'] ?? [], "{$stage} is built from nothing");

            foreach ($entry['recipe'] as $ingredient => $count) {
                $this->assertArrayHasKey(
                    $ingredient,
                    $config['bag'],
                    "{$stage} needs an item that does not exist",
                );
                $this->assertGreaterThan(0, $count, "{$stage} asks for no {$ingredient}");
            }
        }
    }

    /**
     * The order in this map IS the arc: a stage's predecessor is the key
     * before it. A later stage that cost less than an earlier one would make
     * the upgrade read as a discount.
     */
    public function test_each_stage_costs_more_than_the_one_before_it(): void
    {
        $costs = array_map(
            static fn (array $entry): int => array_sum($entry['recipe']),
            $this->config()['shelter'],
        );

        $ascending = array_values($costs);
        sort($ascending);

        $this->assertSame($ascending, array_values($costs));
        $this->assertSame(count($costs), count(array_unique($costs)), 'two stages cost the same');
    }

    /**
     * E1's rule, in the one place F3 could break it: the cabin's floor may
     * stop GRANTING the cabin, but the number may never relocate.
     */
    public function test_only_the_cabin_names_a_floor_and_it_is_still_five(): void
    {
        $withFloors = array_filter(
            $this->config()['shelter'],
            static fn (array $entry): bool => array_key_exists('insights', $entry),
        );

        $this->assertSame(['cabin'], array_keys($withFloors));
        $this->assertSame(5, $withFloors['cabin']['insights']);
    }

    /**
     * A stage is consumed in ONE act, so the bag has to be able to hold its
     * whole price at once. The dearest stage against every container the game
     * has is the check that a later tuning pass cannot quietly price a shelter
     * out of reach of any bag that can be built.
     *
     * Note what this does NOT claim: that the price fits in the BASE bag. It
     * does not, deliberately — the cabin needs at least two containers, which
     * is a real fact about the arc and is written up in `docs/BLOB.md`.
     */
    public function test_the_dearest_stage_fits_in_a_bag_that_can_actually_be_built(): void
    {
        $config = $this->config();

        $reachable = (int) $config['capacity']['base'] + (int) array_sum(array_map(
            static fn (array $item): int => (int) ($item['capacity'] ?? 0),
            array_filter(
                $config['bag'],
                static fn (array $item): bool => $item['category'] === 'container',
            ),
        ));

        $dearest = max(array_map(
            static fn (array $entry): int => array_sum($entry['recipe']),
            $config['shelter'],
        ));

        $this->assertLessThanOrEqual(
            $reachable,
            $dearest,
            'the dearest shelter cannot be held in any bag this game can build',
        );
        $this->assertGreaterThan(
            (int) $config['capacity']['base'],
            $dearest,
            'this assertion stops meaning anything if the base bag can hold it',
        );
    }

    /**
     * What the cabin cost to put up is what comes back when it comes apart.
     * Tied to the recipes rather than written out twice, so retuning a stage
     * cannot leave an established account short without anything saying so.
     */
    public function test_the_salvage_is_worth_exactly_what_the_whole_arc_costs(): void
    {
        $config = $this->config();

        $whole = array_sum(array_map(
            static fn (array $entry): int => array_sum($entry['recipe']),
            $config['shelter'],
        ));

        $this->assertSame($whole, (int) $config['salvage']['stock']);
    }

    /**
     * A stage's line is shown to the user, so it follows the same copy rules
     * as the ladder's: it describes Blob, it never congratulates, it carries no
     * exclamation mark, and it names the companion with a token rather than
     * with the literal.
     */
    public function test_a_stages_copy_follows_the_same_rules_as_the_ladders(): void
    {
        foreach ($this->config()['shelter'] as $stage => $entry) {
            $this->assertArrayHasKey('built', $entry, "{$stage} says nothing when it goes up");
            $this->assertStringNotContainsString('!', $entry['built'], "{$stage}.built exclaims");
            $this->assertStringNotContainsString('Blob', $entry['built'], "{$stage}.built hardcodes the name");
            $this->assertStringContainsString('{name}', $entry['built'], "{$stage}.built never names the companion");
        }
    }
}
