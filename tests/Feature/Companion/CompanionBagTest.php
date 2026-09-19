<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildItem;
use App\Actions\MeetNode;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionBag;
use App\Services\Companion\CompanionEconomyException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One answer to "what does the screen need", so the controller does not
 * assemble it and neither does the page.
 *
 * The payload is where a total would first appear, before any pixel is drawn —
 * so this asserts the SHAPE as well as the values. A key called `skill_count`
 * would sail past every rendering test in the suite and then show up in the
 * bag as a number you can be behind on.
 */
class CompanionBagTest extends TestCase
{
    use RefreshDatabase;

    private function bag(User $user): array
    {
        return app(CompanionBag::class)->forUser($user);
    }

    /** Enough outcomes on enough days to clear a 20 xp price. */
    private function richUser(int $days = 8): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $base = CarbonImmutable::parse('2026-05-01T09:00:00+00:00');

        for ($index = 0; $index < $days; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $base->addDays($index),
            ]);
        }

        return $user;
    }

    /**
     * A third node that needs both a skill and a tool. The trunk, the rope and
     * the axe are all real shipped content now, so this no longer stands in
     * for the day's content — it pins the trunk's shape, so these cases do not
     * move when content does.
     */
    private function authorATrunk(): void
    {
        config()->set('companion.skills.chop-wood', [
            'price' => 20,
            'node' => 'trunk',
            'label' => 'chop wood',
        ]);
        config()->set('companion.nodes.trunk', [
            'skill' => 'chop-wood',
            'tool' => 'axe',
            'yields' => 'timber',
            'label' => 'the fallen trunk',
            'met' => '{name} climbs onto the fallen trunk and sits there a while.',
            'blunt' => '{name} looks at the trunk. Nothing it is carrying will bite into it.',
            'took' => '{name} drags back {count} timber.',
            'empty' => '{name} checks the trunk. There is nothing loose on it.',
            'full' => 'There is nowhere to put it. The timber stays by the trunk.',
        ]);
        config()->set('companion.bag.timber', ['category' => 'material', 'label' => 'timber']);
        config()->set('companion.bag.rope', [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ]);
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ]);
    }

    /**
     * The sawing half of F2's content, pinned rather than authored.
     *
     * `handsaw`, `planks` and `crate` are real, shipped config now — this sets
     * them byte-for-byte identical to `config/companion.php`. Kept anyway,
     * rather than deleted in favour of the real config, so its callers keep
     * testing the SHAPE this gate is about (a tool two links deep in a chain)
     * rather than whatever numbers a later tuning pass gives the shipped
     * recipe. {@see authorATrunk()} got the same treatment when its own
     * content shipped, for the same reason.
     */
    private function authorASaw(): void
    {
        config()->set('companion.bag.handsaw', [
            'category' => 'tool',
            'label' => 'handsaw',
            'recipe' => ['timber' => 2, 'rope' => 1],
        ]);
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'tool' => 'handsaw',
            'recipe' => ['timber' => 1],
            'makes' => 3,
        ]);
        config()->set('companion.bag.crate', [
            'category' => 'container',
            'label' => 'crate',
            'recipe' => ['planks' => 4],
            'capacity' => 5,
        ]);
    }

    /**
     * Nothing met, nothing held, nothing chosen. Every list is empty rather
     * than absent, so the screen never has to guess whether a key exists.
     */
    public function test_an_untouched_account_is_empty_rather_than_absent(): void
    {
        $bag = $this->bag(User::factory()->create());

        $this->assertSame(0, $bag['xp']);
        $this->assertSame(5, $bag['capacity']);
        $this->assertSame(0, $bag['held']);
        $this->assertSame([], $bag['items']);
        $this->assertSame([], $bag['skills']);
        $this->assertSame([], $bag['recipes']);
        $this->assertSame('Blob', $bag['name']);

        // The world is the exception, and deliberately so: both nodes are
        // visible in the scene from the start, unmet and unusable. A node
        // standing in the clearing is a thing that is there, not a preview of
        // something you have not done.
        $this->assertSame(['reeds', 'deadfall', 'trunk'], array_column($bag['nodes'], 'node'));
        $this->assertSame([false, false, false], array_column($bag['nodes'], 'met'));
        $this->assertSame([0, 0, 0], array_column($bag['nodes'], 'available'));

        // And no row was written just by looking.
        $this->assertDatabaseCount('companions', 0);
    }

    /** The balance, and nothing about a target. */
    public function test_the_balance_is_what_the_record_has_paid_less_what_was_spent(): void
    {
        $user = $this->richUser();

        $this->assertSame(24, $this->bag($user)['xp']);

        $user->companion()->firstOrCreate([])->update(['xp_spent' => 20]);

        $this->assertSame(4, $this->bag($user)['xp']);
    }

    /**
     * The discovery mechanic, read back out. Meeting a node you cannot use is
     * what puts its skill in the list — with a price, and marked as not yet
     * known.
     */
    public function test_meeting_a_node_puts_its_skill_in_the_list(): void
    {
        $user = $this->richUser();

        $this->assertSame([], $this->bag($user)['skills']);

        app(MeetNode::class)->handle($user, 'reeds');

        $bag = $this->bag($user);

        $this->assertSame([[
            'skill' => 'gather-fibre',
            'label' => 'gather fibre',
            'price' => 20,
            'known' => false,
            'affordable' => true,
        ]], $bag['skills']);
    }

    /** A skill whose node has not been met is absent, not listed as unknown. */
    public function test_a_skill_whose_node_was_never_met_is_absent(): void
    {
        $user = $this->richUser();

        app(MeetNode::class)->handle($user, 'reeds');

        $skills = array_column($this->bag($user)['skills'], 'skill');

        $this->assertSame(['gather-fibre'], $skills);
        $this->assertNotContains('gather-wood', $skills);
    }

    /**
     * An unaffordable skill stays listed WITH its price. That is a menu, not a
     * checklist — you cannot be behind on it, because there is no bottom to
     * reach.
     */
    public function test_an_unaffordable_skill_is_still_listed_with_its_price(): void
    {
        $user = $this->richUser(2);

        app(MeetNode::class)->handle($user, 'reeds');

        $bag = $this->bag($user);

        $this->assertSame(6, $bag['xp']);
        $this->assertSame(20, $bag['skills'][0]['price']);
        $this->assertFalse($bag['skills'][0]['affordable']);
    }

    /** A learned skill stays on the list, marked known rather than removed. */
    public function test_a_learned_skill_stays_listed_as_known(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'reeds']);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $bag = $this->bag($user);

        $this->assertTrue($bag['skills'][0]['known']);
    }

    /** What is standing, for nodes Blob has met. */
    public function test_the_nodes_carry_what_is_standing_at_them(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 4]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $bag = $this->bag($user);

        $this->assertSame([
            [
                'node' => 'reeds',
                'label' => 'the reeds',
                'available' => 4,
                'skill' => 'gather-fibre',
                'met' => true,
                'known' => true,
                'usable' => true,
            ],
            // Standing there the whole time, unmet: the clearing does not
            // appear one node at a time.
            [
                'node' => 'deadfall',
                'label' => 'the fallen branches',
                'available' => 0,
                'skill' => 'gather-wood',
                'met' => false,
                'known' => false,
                'usable' => false,
            ],
            [
                'node' => 'trunk',
                'label' => 'the fallen trunk',
                'available' => 0,
                'skill' => 'chop-wood',
                'met' => false,
                'known' => false,
                'usable' => false,
            ],
        ], $bag['nodes']);
    }

    /**
     * `usable` tracks the tool, not just the skill. The trunk needs both
     * `chop-wood` and an axe — buying the skill before building the axe is
     * exactly the sequence a player can reach, and it must leave the row
     * `known` but not `usable`, never the other way round.
     */
    public function test_usable_tracks_the_tool_the_node_names(): void
    {
        $this->authorATrunk();

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'trunk', 'available' => 2]);
        $companion->skills()->create(['name' => 'chop-wood', 'learned_at' => now()]);

        $trunk = fn (): array => collect($this->bag($user)['nodes'])
            ->firstWhere('node', 'trunk');

        $this->assertTrue($trunk()['known']);
        $this->assertFalse($trunk()['usable']);

        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $this->assertTrue($trunk()['usable']);
    }

    /**
     * A heap is absent from the clearing until something puts it there.
     *
     * Every node of the WORLD is listed whether or not Blob has met it, because
     * a node standing there is a thing that is there rather than a preview. A
     * heap is not in the world until it exists, so listing it would put a
     * salvage pile in front of every account that never had a cabin.
     */
    public function test_a_heap_is_absent_until_something_puts_it_there(): void
    {
        $user = User::factory()->create();

        $this->assertNotContains('salvage', array_column($this->bag($user)['nodes'], 'node'));

        $user->companion()->firstOrCreate([])->nodes()->create([
            'node' => 'salvage',
            'available' => 26,
        ]);

        $heap = collect($this->bag($user)['nodes'])->firstWhere('node', 'salvage');

        // The whole row, not selected fields: a wrong `usable`, a removed
        // key, or an unexpected extra key all fail loudly this way, none of
        // which a field-by-field assertion could see.
        $this->assertSame([
            'node' => 'salvage',
            'label' => 'the heap',
            'available' => 26,
            // Null rather than '': a heap is not a thing you learn to use.
            'skill' => null,
            // The row exists, so it has been placed — and a heap is placed
            // already met.
            'met' => true,
            // Nothing to learn is nothing withheld...
            'known' => true,
            // ...and with no skill and no tool, there is nothing standing
            // between Blob and the heap.
            'usable' => true,
        ], $heap);
    }

    /** A node that names no tool is usable the moment its skill is known. */
    public function test_a_node_naming_no_tool_is_usable_once_known(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 4]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $reeds = collect($this->bag($user)['nodes'])->firstWhere('node', 'reeds');

        $this->assertTrue($reeds['known']);
        $this->assertTrue($reeds['usable']);
    }

    /** Only what is held, and what it costs the bag to hold it. */
    public function test_the_items_are_only_what_is_held(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $bag = $this->bag($user);

        $this->assertSame([[
            'item' => 'fibre',
            'label' => 'fibre',
            'category' => 'material',
            'quantity' => 3,
            'droppable' => true,
        ]], $bag['items']);
        $this->assertSame(3, $bag['held']);
        $this->assertSame(5, $bag['capacity']);
    }

    /** A container raises capacity and does not occupy it. */
    public function test_a_built_container_raises_capacity_without_taking_room(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'basket', 'quantity' => 1]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $bag = $this->bag($user);

        $this->assertSame(10, $bag['capacity']);
        $this->assertSame(2, $bag['held']);
    }

    /**
     * A recipe appears once its ingredient has been met — not before. Knowing
     * a basket exists before knowing what reeds are would be a preview.
     */
    public function test_a_recipe_appears_once_its_ingredient_has_been_met(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $this->bag($user)['recipes']);

        app(MeetNode::class)->handle($user, 'reeds');

        $bag = $this->bag($user);

        $this->assertSame([
            // Rope is made of fibre too, and nothing gates it: no node needs a
            // rope to be worked, so it is knowable the moment fibre is.
            [
                'item' => 'rope',
                'label' => 'rope',
                'recipe' => ['fibre' => 3],
                'tool' => null,
                'buildable' => false,
            ],
            [
                'item' => 'basket',
                'label' => 'basket',
                'recipe' => ['fibre' => 4],
                'tool' => null,
                'buildable' => false,
            ],
        ], $bag['recipes']);
    }

    /** `buildable` flips when the materials are actually there. */
    public function test_buildable_flips_when_the_materials_are_there(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $basket = fn (): array => collect($this->bag($user)['recipes'])
            ->firstWhere('item', 'basket');

        $this->assertFalse($basket()['buildable']);

        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        // Exactly the recipe's price, so this pins the boundary rather than
        // merely clearing it. Addressed by name because the catalogue's order
        // is content's to change, and this assertion has silently moved once
        // already.
        $this->assertTrue($basket()['buildable']);
    }

    /**
     * A recipe carries its tool as part of its price. Not a "requires" and not
     * a lock: the row stays readable and only the button is disabled, exactly
     * as an unaffordable skill stays listed with its price.
     */
    public function test_a_recipe_carries_its_tool_as_part_of_the_price(): void
    {
        $this->authorASaw();
        config()->set('companion.bag.basket.tool', 'handsaw');

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        // The trunk too: a tool has to be something Blob has been SHOWN before
        // a recipe may quote it as a price. Reeds alone leave the handsaw
        // unaccountable, and an unaccountable tool hides what needs it.
        app(MeetNode::class)->handle($user, 'trunk');

        // By name, not by position: rope is knowable from fibre alone and
        // sorts ahead of the basket, and what else the clearing has taught
        // Blob is not what this test is about.
        $basket = collect($this->bag($user)['recipes'])->firstWhere('item', 'basket');

        $this->assertSame([
            'item' => 'basket',
            'label' => 'basket',
            'recipe' => ['fibre' => 4],
            'tool' => 'handsaw',
            'buildable' => false,
        ], $basket);
    }

    /** A recipe that needs nothing in hand says so with null, never an empty string. */
    public function test_a_recipe_that_needs_no_tool_carries_null(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $basket = collect($this->bag($user)['recipes'])->firstWhere('item', 'basket');

        $this->assertNull($basket['tool']);
    }

    /** Holding the materials is not enough when the tool is missing. */
    public function test_buildable_stays_false_without_the_tool(): void
    {
        $this->authorASaw();
        config()->set('companion.bag.basket.tool', 'handsaw');

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        // The trunk too: a tool has to be something Blob has been SHOWN before
        // a recipe may quote it as a price. Reeds alone leave the handsaw
        // unaccountable, and an unaccountable tool hides what needs it.
        app(MeetNode::class)->handle($user, 'trunk');
        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        $basket = fn (): array => collect($this->bag($user)['recipes'])
            ->firstWhere('item', 'basket');

        $this->assertFalse($basket()['buildable']);

        $user->companion->items()->create(['item' => 'handsaw', 'quantity' => 1]);

        $this->assertTrue($basket()['buildable']);
    }

    /** A renamed companion is named here, because the surfaces read it from here. */
    public function test_the_name_comes_through(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->assertSame('Pebble', $this->bag($user)['name']);
    }

    /**
     * THE GUARD, applied to the payload rather than to the pixels.
     *
     * No total, no count of what exists, no percentage, no "next". A key named
     * for any of those would pass every rendering test in the suite and then
     * appear in the bag as a number you can be behind on.
     */
    public function test_the_payload_names_no_total_and_no_next(): void
    {
        $user = $this->richUser();
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');

        // Without a held item, `items` is empty and contributes no keys to the
        // sweep below — the one list whose row shape this phase widened
        // (`category` can now be `tool` as well as `consumable`) would go
        // unchecked. Do not delete this as unused setup.
        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        // Without a met trunk the shelter offer is null and its own keys never
        // enter the sweep — the one shape this phase added would go unchecked.
        // Do not delete this as unused setup.
        app(MeetNode::class)->handle($user, 'trunk');

        $bag = $this->bag($user);

        $keys = [...array_keys($bag)];

        foreach (['items', 'nodes', 'skills', 'recipes'] as $list) {
            foreach ($bag[$list] as $row) {
                $keys = [...$keys, ...array_keys($row)];
            }
        }

        // The shelter is a shape rather than a list, and its inner keys would
        // otherwise never be swept. `offer` is named the way it is precisely
        // because `next` is on the list below.
        $keys = [...$keys, ...array_keys($bag['shelter'])];

        if ($bag['shelter']['offer'] !== null) {
            $keys = [...$keys, ...array_keys($bag['shelter']['offer'])];
        }

        foreach ($keys as $key) {
            $this->assertDoesNotMatchRegularExpression(
                '/total|count|percent|progress|next|remaining|locked|streak/i',
                $key,
                "[{$key}] is a key the bag must not carry",
            );
        }
    }

    /** One person's bag is never another's. */
    public function test_another_users_bag_is_not_this_one(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        app(MeetNode::class)->handle($stranger, 'reeds');
        $stranger->companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $bag = $this->bag($user);

        $this->assertSame([], $bag['items']);
        $this->assertSame([], $bag['skills']);
        // The world is everyone's; what has been done to it is not.
        $this->assertSame([false, false, false], array_column($bag['nodes'], 'met'));
        $this->assertSame([0, 0, 0], array_column($bag['nodes'], 'available'));
    }

    /**
     * An ingredient does not have to come out of the ground. A recipe whose
     * ingredients are themselves knowable recipes is knowable too — otherwise
     * nothing built from something built could ever be listed, however much of
     * it Blob is carrying.
     */
    public function test_a_recipe_made_of_built_things_is_knowable(): void
    {
        config()->set('companion.bag.rope', [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ]);
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ]);

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');
        // The axe is the trunk's tool now, so being knowable is no longer
        // enough on its own — it also waits for the thing it is for.
        app(MeetNode::class)->handle($user, 'trunk');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // rope from fibre, and the axe from deadfall and that rope.
        $this->assertContains('rope', $listed);
        $this->assertContains('axe', $listed);
    }

    /** The chain still has to bottom out in something Blob has actually met. */
    public function test_a_recipe_whose_chain_never_reaches_a_met_node_is_absent(): void
    {
        config()->set('companion.bag.rope', [
            'category' => 'consumable',
            'label' => 'rope',
            'recipe' => ['fibre' => 3],
        ]);
        config()->set('companion.bag.axe', [
            'category' => 'tool',
            'label' => 'axe',
            'recipe' => ['deadfall' => 2, 'rope' => 1],
        ]);

        $user = User::factory()->create();

        // The reeds only. Rope is reachable; the axe needs deadfall, which
        // Blob has never seen.
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        $this->assertContains('rope', $listed);
        $this->assertNotContains('axe', $listed);
    }

    /**
     * Config is authored data, and an authored cycle would recurse forever on
     * an ordinary page load. Resolving by fixed point rather than by recursion
     * is what makes this terminate; this is the test that says so.
     */
    public function test_an_authored_recipe_cycle_terminates(): void
    {
        config()->set('companion.bag.knot', [
            'category' => 'consumable',
            'label' => 'knot',
            'recipe' => ['loop' => 1],
        ]);
        config()->set('companion.bag.loop', [
            'category' => 'consumable',
            'label' => 'loop',
            'recipe' => ['knot' => 1],
        ]);

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // Neither is reachable from a met node, and asking took finite time.
        $this->assertNotContains('knot', $listed);
        $this->assertNotContains('loop', $listed);
    }

    /**
     * The chain resolved in the order config happens to be written in, which is
     * not the order it depends in.
     *
     * `torch` is authored BEFORE the `rope` it is made of, so a single scan of
     * the catalogue reaches it while rope is still unknown and passes it over.
     * Only a second pass picks it up. That is what the fixed point buys, and it
     * is the reason this resolves by repeated passes rather than by one walk:
     * nothing constrains an author to write a recipe after its ingredients.
     */
    public function test_a_recipe_authored_before_its_ingredient_still_resolves(): void
    {
        // The whole catalogue, not two keys set into the live one. `config()->set`
        // replaces an existing key IN PLACE and appends only new ones, so once
        // `rope` is real content it sits ahead of `torch` and the adversarial
        // ordering this test exists for quietly disappears. An ordering test
        // whose order depends on the rest of the game is not an ordering test.
        config()->set('companion.bag', [
            'fibre' => ['category' => 'material', 'label' => 'fibre'],
            'torch' => ['category' => 'tool', 'label' => 'torch', 'recipe' => ['rope' => 1]],
            'rope' => ['category' => 'consumable', 'label' => 'rope', 'recipe' => ['fibre' => 3]],
        ]);

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        $this->assertContains('rope', $listed);
        $this->assertContains('torch', $listed);
    }

    /**
     * A tool's recipe waits for the thing it is for.
     *
     * The axe is knowable from deadfall and rope the moment both are met — but
     * listing it then would be a tool with no reason attached. Meeting the node
     * that needs it is what gives it one, and that is the same encounter that
     * puts the node's skill in the skill list: one click, both halves of what
     * the trunk needs.
     */
    public function test_a_tool_is_not_listed_until_its_node_has_been_met(): void
    {
        $this->authorATrunk();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // Both of the axe's ingredients are knowable, and the axe is still not
        // listed, because Blob has never seen the trunk.
        $this->assertContains('rope', $listed);
        $this->assertNotContains('axe', $listed);

        app(MeetNode::class)->handle($user, 'trunk');

        $this->assertContains('axe', array_column($this->bag($user)['recipes'], 'item'));
    }

    /** A recipe no node names is knowable from its ingredients alone. */
    public function test_a_recipe_no_node_needs_is_listed_on_its_ingredients_alone(): void
    {
        $this->authorATrunk();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        // Rope gates nothing, so nothing has to be met for it beyond the fibre
        // it is made of.
        $this->assertContains('rope', array_column($this->bag($user)['recipes'], 'item'));
    }

    /**
     * A recipe waits for its tool the way a tool waits for its node.
     *
     * Sawing is knowable the moment timber is — but naming the handsaw in its
     * price while the handsaw itself is nowhere on the screen would be a price
     * quoted in a currency nobody has been shown. The rule the feature already
     * keeps for node tools is the same rule from the other end.
     */
    public function test_a_recipe_waits_for_the_tool_it_names(): void
    {
        $this->authorASaw();

        $user = User::factory()->create();

        // The trunk alone: timber is accounted for, so planks would be
        // knowable — but nothing has shown Blob a handsaw.
        app(MeetNode::class)->handle($user, 'trunk');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        $this->assertNotContains('planks', $listed);
        $this->assertNotContains('handsaw', $listed);
    }

    /** Once the tool is on the screen, what it is for arrives with it. */
    public function test_a_recipe_appears_once_its_tool_is_shown(): void
    {
        $this->authorASaw();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        // Reeds bring fibre, so rope is accounted for; rope and timber make
        // the handsaw accountable; the handsaw makes planks accountable.
        $this->assertContains('handsaw', $listed);
        $this->assertContains('planks', $listed);
    }

    /**
     * The spec's own sentence, as a test: clicking the trunk first, on a
     * clearing where nothing else has been touched, reveals the skill and no
     * recipes at all. (F2 §4.5)
     */
    public function test_meeting_only_the_trunk_still_reveals_no_recipes(): void
    {
        $this->authorASaw();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');

        $bag = $this->bag($user);

        $this->assertSame(['chop-wood'], array_column($bag['skills'], 'skill'));
        $this->assertSame([], $bag['recipes']);
    }

    /**
     * Being blocked travels. A thing made from something Blob has not been
     * shown is not shown either, however ordinary its own price looks.
     *
     * The crate names no tool, so nothing about the crate itself is gated —
     * but it is made of planks, and planks wait for a handsaw nobody has
     * seen. Listing it would put "4 planks" on a screen where planks do not
     * appear, which is the thing the gate exists to prevent, one link further
     * along than the gate used to reach.
     */
    public function test_being_blocked_travels_down_the_chain(): void
    {
        $this->authorASaw();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        $this->assertNotContains('crate', $listed);
        $this->assertNotContains('planks', $listed);
        $this->assertNotContains('handsaw', $listed);
    }

    /** And it un-blocks the same way, all the way down. */
    public function test_the_whole_chain_arrives_once_its_first_tool_does(): void
    {
        $this->authorASaw();

        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $listed = array_column($this->bag($user)['recipes'], 'item');

        $this->assertContains('handsaw', $listed);
        $this->assertContains('planks', $listed);
        $this->assertContains('crate', $listed);
    }

    /**
     * C1: `buildable` must not promise a build BuildItem is about to refuse.
     *
     * `HarvestNode` fills the bag by design — it takes `min(available, room)`
     * — so a full bag is the ordinary result of chopping the trunk, not an
     * edge case. Here Blob holds the handsaw and exactly five timber in a
     * five-slot bag: the tool is on the belt, the ingredient is held, so the
     * old `buildable` (tool held AND ingredients held) said yes. But planks
     * makes three from one timber — a net +2 — and there is no room for that.
     * `buildable` and BuildItem are asserted together, on real shipped
     * config, so the two can never quietly disagree again.
     */
    public function test_buildable_agrees_with_build_item_about_a_full_bag(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        // Both nodes met: timber accounts for planks' ingredient, and rope
        // (from fibre) plus timber accounts for the handsaw named as its
        // tool — the same chain test_a_recipe_appears_once_its_tool_is_shown
        // walks to get planks onto the list at all.
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $companion->items()->create(['item' => 'handsaw', 'quantity' => 1]);
        $companion->items()->create(['item' => 'timber', 'quantity' => 5]);

        $this->assertSame(5, $companion->fresh()->load('items')->held());
        $this->assertSame(5, $companion->fresh()->load('items')->capacity());

        $planks = collect($this->bag($user)['recipes'])->firstWhere('item', 'planks');

        $this->assertNotNull($planks, 'planks should stay listed even though it cannot be built right now');
        $this->assertFalse($planks['buildable']);

        $this->expectException(CompanionEconomyException::class);

        app(BuildItem::class)->handle($user, 'planks');
    }

    /**
     * A row says whether it is a thing the bag can be relieved of. The client
     * could work it out from the category, but that would put the carried list
     * in two places and they would eventually disagree about what a tool is.
     */
    public function test_a_held_row_says_whether_it_can_be_tipped_out(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);
        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);
        $companion->items()->create(['item' => 'basket', 'quantity' => 1]);

        $rows = collect($this->bag($user)['items'])->keyBy('item');

        $this->assertTrue($rows['fibre']['droppable']);
        $this->assertFalse($rows['axe']['droppable']);
        $this->assertFalse($rows['basket']['droppable']);
    }

    /**
     * THE INVARIANT the whole batch rests on, asserted from both ends this
     * covers, rather than left implicit or checked against a copy of
     * itself: every category `droppable` marks true matches
     * `companion.capacity.carried`, AND {@see Companion::held()} counts
     * exactly the rows that came back droppable — the same key read by two
     * independent pieces of code, agreeing in fact rather than by
     * definition. Because they agree, `room() === 0` always implies at
     * least one held stack can be tipped out — no full bag is ever
     * inescapable, which is the dead end this batch exists to close. If
     * `held()` is ever given its own list, or `droppable` stops honouring a
     * carried category, the two diverge and the trap reopens with a green
     * suite: a full bag holding nothing that can be put down.
     *
     * Covers all four categories on one companion, including `rope` — the
     * consumable, which no other case here asserts `droppable` for.
     */
    public function test_droppable_agrees_with_carried_for_every_category(): void
    {
        /** @var array<string, array<string, mixed>> $catalogue */
        $catalogue = (array) config('companion.bag', []);

        /** @var list<string> $carried */
        $carried = (array) config('companion.capacity.carried', []);

        $isCarried = static fn (string $item): bool => in_array(
            (string) ($catalogue[$item]['category'] ?? ''),
            $carried,
            true,
        );

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        // One of each of the four categories, and exactly one each:
        // held() sums quantity over carried categories, so with one of
        // every item held it equals the count of carried items, which is
        // the count of droppable rows below.
        $held = ['fibre', 'rope', 'axe', 'basket'];

        foreach ($held as $item) {
            $companion->items()->create(['item' => $item, 'quantity' => 1]);
        }

        $expectedDroppable = array_values(array_filter($held, $isCarried));

        $bag = $this->bag($user);

        $rows = collect($bag['items'])->keyBy('item');

        $actualDroppable = $rows
            ->filter(static fn (array $row): bool => $row['droppable'])
            ->keys()
            ->all();

        sort($expectedDroppable);
        sort($actualDroppable);

        $this->assertSame($expectedDroppable, $actualDroppable);

        // held() and droppable must be answering the same question. With
        // one of every item held, the number of droppable rows IS what
        // held() counts — and if either ever stops reading
        // capacity.carried, or reads it differently, these two diverge and
        // a bag can fill with something that cannot be put down. That is
        // the dead end this batch exists to close.
        $droppable = array_filter($bag['items'], static fn (array $row): bool => $row['droppable']);

        $this->assertSame(count($droppable), $bag['held']);
    }

    /**
     * Only the stage that is choosable now, and only once its price is in a
     * currency Blob has been shown. A brand-new account seeing "lean-to, 4
     * planks" before it has met the trunk would be a price quoted in something
     * that does not exist for it yet — the same defect the recipe list's own
     * reveal gate was built to stop, one list along.
     */
    public function test_the_shelter_is_absent_until_its_price_can_be_accounted_for(): void
    {
        $user = User::factory()->create();

        $shelter = $this->bag($user)['shelter'];

        $this->assertNull($shelter['built']);
        $this->assertNull($shelter['offer']);
    }

    /** Once planks are accountable, the first stage — and only the first. */
    public function test_only_the_stage_that_is_choosable_now_is_listed(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $offer = $this->bag($user)['shelter']['offer'];

        $this->assertSame('lean-to', $offer['stage']);
        $this->assertSame(['planks' => 4], $offer['recipe']);
        $this->assertFalse($offer['buildable']);
    }

    /** It advances with what is built, one at a time and never two. */
    public function test_the_offer_advances_as_the_shelter_does(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'lean-to']);

        $bag = $this->bag($user);

        $this->assertSame('lean-to', $bag['shelter']['built']);
        $this->assertSame('hut', $bag['shelter']['offer']['stage']);
    }

    /**
     * A stage behind a floor the record has not reached is ABSENT, not listed
     * and greyed. A price you cannot pay yet is a menu; a fact about the record
     * you cannot pay at all is a lock, and the feature answers it with silence
     * rather than naming what you are waiting for.
     */
    public function test_a_stage_behind_a_floor_the_record_has_not_reached_is_absent(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'hut']);

        $this->assertNull($this->bag($user)['shelter']['offer']);
    }

    /** And arrives, unannounced, when the record reaches it. */
    public function test_the_cabin_arrives_on_the_offer_once_the_floor_is_reached(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'hut']);

        for ($index = 0; $index < 5; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }

        $this->assertSame('cabin', $this->bag($user)['shelter']['offer']['stage']);
    }

    /** Nothing is offered once the arc is finished. There is no fourth stage. */
    public function test_a_finished_shelter_offers_nothing(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $user->companion->update(['shelter' => 'cabin']);

        $bag = $this->bag($user);

        $this->assertSame('cabin', $bag['shelter']['built']);
        $this->assertNull($bag['shelter']['offer']);
    }

    /**
     * `buildable` asks the same question BuildShelter asks before it writes
     * anything, for the same reason the recipe list does: a read that promises
     * a build the action is about to refuse is the defect F2 shipped once.
     */
    public function test_buildable_flips_when_the_planks_are_there(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        $offer = fn (): array => $this->bag($user)['shelter']['offer'];

        $this->assertFalse($offer()['buildable']);

        // Exactly the price, so this pins the boundary rather than clearing it.
        $user->companion->items()->create(['item' => 'planks', 'quantity' => 4]);

        $this->assertTrue($offer()['buildable']);
    }
}
