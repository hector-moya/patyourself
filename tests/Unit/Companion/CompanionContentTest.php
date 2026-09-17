<?php

namespace Tests\Unit\Companion;

use PHPUnit\Framework\TestCase;

/**
 * F1's content, checked for the ways config can lie.
 *
 * A pure read of `config/companion.php`, in the same style as
 * {@see CompanionLadderTest}: no database, no container, just the authored data
 * and the invariants it has to satisfy for anything reading it to make sense.
 *
 * The four-type wearable cap is NOT policed here. It belongs to `item_types`
 * and stays guarded by CompanionLadderTest. These categories are uncapped on
 * purpose — the cap was always about clutter on a 64x64 sprite, and nothing in
 * `bag` is drawn on Blob.
 */
class CompanionContentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(): array
    {
        return require __DIR__.'/../../../config/companion.php';
    }

    public function test_every_skill_names_a_node_that_exists(): void
    {
        $config = $this->config();

        $this->assertNotEmpty($config['skills']);

        foreach ($config['skills'] as $name => $skill) {
            $this->assertArrayHasKey('price', $skill, "{$name} has no price");
            $this->assertGreaterThan(0, $skill['price'], "{$name} is free");
            $this->assertNotSame('', trim((string) $skill['label']), "{$name} has no label");
            $this->assertArrayHasKey(
                $skill['node'],
                $config['nodes'],
                "{$name} unlocks a node that does not exist",
            );
        }
    }

    public function test_every_node_names_a_skill_and_a_material_that_exist(): void
    {
        $config = $this->config();

        $this->assertNotEmpty($config['nodes']);

        foreach ($config['nodes'] as $name => $node) {
            $this->assertArrayHasKey(
                $node['skill'],
                $config['skills'],
                "{$name} needs a skill that does not exist",
            );
            $this->assertArrayHasKey(
                $node['yields'],
                $config['bag'],
                "{$name} yields an item that does not exist",
            );
            $this->assertSame(
                'material',
                $config['bag'][$node['yields']]['category'],
                "{$name} yields something that is not a material",
            );
        }
    }

    /**
     * Each skill unlocks its own node and no other. A skill pointing at a node
     * that points back at a different skill would leave one node permanently
     * unusable with nothing on screen to explain it.
     */
    public function test_skills_and_nodes_point_at_each_other(): void
    {
        $config = $this->config();

        foreach ($config['skills'] as $name => $skill) {
            $this->assertSame(
                $name,
                $config['nodes'][$skill['node']]['skill'],
                "{$name} unlocks a node that names a different skill",
            );
        }
    }

    public function test_every_recipe_asks_for_items_that_exist(): void
    {
        $config = $this->config();

        foreach ($config['bag'] as $name => $item) {
            $this->assertArrayHasKey('category', $item, "{$name} has no category");
            $this->assertNotSame('', trim((string) $item['label']), "{$name} has no label");

            foreach ($item['recipe'] ?? [] as $ingredient => $count) {
                $this->assertArrayHasKey(
                    $ingredient,
                    $config['bag'],
                    "{$name} needs an item that does not exist",
                );
                $this->assertGreaterThan(0, $count, "{$name} asks for no {$ingredient}");
            }
        }
    }

    /**
     * The fork is real on day one: each of F1's two materials builds exactly one
     * container, so the first 20 xp spent decides which one is standing in the
     * clearing and the other is not. (F1 §1)
     */
    public function test_each_material_builds_exactly_one_container(): void
    {
        $config = $this->config();

        $materials = array_keys(array_filter(
            $config['bag'],
            static fn (array $item): bool => $item['category'] === 'material',
        ));

        $this->assertNotEmpty($materials);

        foreach ($materials as $material) {
            $built = array_filter(
                $config['bag'],
                static fn (array $item): bool => $item['category'] === 'container'
                    && array_key_exists($material, $item['recipe'] ?? []),
            );

            $this->assertCount(1, $built, "{$material} should build exactly one container");
        }
    }

    public function test_every_container_raises_capacity(): void
    {
        foreach ($this->config()['bag'] as $name => $item) {
            if ($item['category'] !== 'container') {
                continue;
            }

            $this->assertGreaterThan(0, $item['capacity'] ?? 0, "{$name} adds no capacity");
            $this->assertNotEmpty($item['recipe'] ?? [], "{$name} is built from nothing");
        }
    }

    /**
     * A container does not occupy the space it creates, and a tool — when F2
     * adds one — is on the belt rather than in the bag.
     */
    public function test_only_carried_categories_take_up_room(): void
    {
        $config = $this->config();

        $this->assertSame(['material', 'consumable'], $config['capacity']['carried']);
        $this->assertSame(5, $config['capacity']['base']);
        $this->assertNotContains('container', $config['capacity']['carried']);
    }

    /**
     * Nothing in `bag` may reuse a wearable's name. The two live in different
     * tables and mean different things — one is drawn on Blob and capped at
     * four forever, the other is carried and uncapped — and a shared name would
     * make "scarf" ambiguous in every message and every lookup.
     */
    public function test_the_bag_never_reuses_a_wearable_name(): void
    {
        $config = $this->config();

        foreach ($config['item_types'] as $wearable) {
            $this->assertArrayNotHasKey(
                $wearable,
                $config['bag'],
                "[{$wearable}] is both worn and carried",
            );
        }
    }

    /**
     * A node's two lines are shown to the user, so they follow the same copy
     * rules as the ladder's: they describe Blob, they never congratulate, and
     * they carry no exclamation mark.
     *
     * `met` uses the {name} token rather than the literal "Blob" — a renamed
     * companion referring to itself in the third person is the exact bug F1 §7
     * exists to avoid, and writing the literal here now would plant one for
     * Batch 3 to miss. `full` never names the companion at all: it is about
     * where the thing stayed, not about who failed to pick it up.
     */
    public function test_a_nodes_copy_follows_the_same_rules_as_the_ladders(): void
    {
        foreach ($this->config()['nodes'] as $name => $node) {
            foreach (['met', 'full'] as $line) {
                $this->assertArrayHasKey($line, $node, "{$name} has no {$line} line");
                $this->assertStringNotContainsString('!', $node[$line], "{$name}.{$line} exclaims");
                $this->assertStringNotContainsString('Blob', $node[$line], "{$name}.{$line} hardcodes the name");
            }

            $this->assertStringContainsString('{name}', $node['met'], "{$name}.met never names the companion");
        }
    }

    /**
     * An insight kind config prices but the resolver never emits would be paid
     * for and never earned; one the resolver emits and config does not price
     * would be earned and never paid. Both are silent.
     */
    public function test_every_priced_insight_is_one_the_resolver_emits(): void
    {
        $this->assertSame(
            ['concluded-experiment', 'started-experiment', 'chain-correction', 'reflection'],
            array_keys($this->config()['xp']['insight']),
        );
    }

    /** The floor repeats forever, so nothing recorded ever goes unpaid. */
    public function test_the_outcome_taper_never_reaches_zero(): void
    {
        $taper = $this->config()['xp']['outcome'];

        $this->assertNotEmpty($taper);

        foreach ($taper as $value) {
            $this->assertGreaterThan(0, $value);
        }

        // Descending: a taper that went back up would pay more for the tenth
        // log of a day than the second.
        $sorted = $taper;
        rsort($sorted);

        $this->assertSame($sorted, $taper);
    }
}
