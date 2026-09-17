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
     * F1's fork, kept and named.
     *
     * This was written as "each material builds exactly one container", which
     * was true of F1 and was never a law — it was F1 §1's claim that the fork
     * is real on day one, expressed as a test. F2 breaks the general form
     * truthfully: timber builds a tool and a material and no container at all.
     * The claim worth keeping is the specific one.
     */
    public function test_the_first_fork_is_still_real_on_day_one(): void
    {
        $config = $this->config();

        foreach (['fibre' => 'basket', 'deadfall' => 'barrow'] as $material => $expected) {
            $built = array_keys(array_filter(
                $config['bag'],
                static fn (array $item): bool => $item['category'] === 'container'
                    && array_key_exists($material, $item['recipe'] ?? []),
            ));

            $this->assertSame([$expected], $built, "{$material} should build exactly {$expected}");
        }
    }

    /**
     * Every tool a node or a recipe asks for is a thing that exists, is
     * categorised as a tool, and can actually be built. A tool named but not
     * buildable is a gate with no key.
     */
    public function test_every_tool_asked_for_exists_and_can_be_built(): void
    {
        $config = $this->config();

        $asked = [];

        foreach ($config['nodes'] as $name => $node) {
            if (isset($node['tool'])) {
                $asked[$node['tool']] = "node {$name}";
            }
        }

        foreach ($config['bag'] as $name => $item) {
            if (isset($item['tool'])) {
                $asked[$item['tool']] = "recipe {$name}";
            }
        }

        $this->assertNotEmpty($asked);

        foreach ($asked as $tool => $who) {
            $this->assertArrayHasKey($tool, $config['bag'], "{$who} needs a tool that does not exist");
            $this->assertSame('tool', $config['bag'][$tool]['category'], "{$who} needs something that is not a tool");
            $this->assertNotEmpty($config['bag'][$tool]['recipe'] ?? [], "[{$tool}] is a gate with no key");
        }
    }

    /** A tool is on the belt. It never occupies the room it lets you use. */
    public function test_a_tool_is_never_a_carried_category(): void
    {
        $this->assertNotContains('tool', $this->config()['capacity']['carried']);
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
     * A node's five lines are shown to the user, so they follow the same copy
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
            foreach (['met', 'blunt', 'took', 'empty', 'full'] as $line) {
                if ($line === 'blunt' && ! array_key_exists($line, $node)) {
                    // Only a node that asks for a tool has a blunt line.
                    continue;
                }

                $this->assertArrayHasKey($line, $node, "{$name} has no {$line} line");
                $this->assertStringNotContainsString('!', $node[$line], "{$name}.{$line} exclaims");
                $this->assertStringNotContainsString('Blob', $node[$line], "{$name}.{$line} hardcodes the name");
            }

            if (isset($node['tool'])) {
                $this->assertArrayHasKey('blunt', $node, "{$name} asks for a tool and says nothing about it");
                $this->assertStringContainsString('{name}', $node['blunt'], "{$name}.blunt never names the companion");
                $this->assertStringNotContainsString(
                    $node['tool'],
                    $node['blunt'],
                    "{$name}.blunt names the tool, which is the app stating a plan",
                );
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

    /**
     * No material is a dead end.
     *
     * This is what replaces the general form of the old container rule. A
     * material or consumable nothing consumes is content that leads nowhere,
     * and it is the property worth holding across every later phase — unlike
     * "exactly one container", which was only ever true of F1.
     *
     * Tools and containers are terminal on purpose: they are what the chain is
     * FOR.
     */
    public function test_no_material_is_a_dead_end(): void
    {
        $config = $this->config();

        $consumed = [];

        foreach ($config['bag'] as $item) {
            foreach (array_keys($item['recipe'] ?? []) as $ingredient) {
                $consumed[$ingredient] = true;
            }
        }

        foreach ($config['bag'] as $name => $item) {
            if (! in_array($item['category'], ['material', 'consumable'], true)) {
                continue;
            }

            $this->assertArrayHasKey($name, $consumed, "[{$name}] is carried and nothing is made from it");
        }
    }

    /** A recipe that makes several says so with a count above one. */
    public function test_a_stated_count_is_a_real_count(): void
    {
        foreach ($this->config()['bag'] as $name => $item) {
            if (! array_key_exists('makes', $item)) {
                continue;
            }

            $this->assertGreaterThan(1, $item['makes'], "[{$name}] states a count of one, which is the default");
        }
    }
}
