<?php

namespace Tests\Feature\Companion;

use App\Actions\MeetNode;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\Companion\CompanionBag;
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
        $this->assertSame(['reeds', 'deadfall'], array_column($bag['nodes'], 'node'));
        $this->assertSame([false, false], array_column($bag['nodes'], 'met'));
        $this->assertSame([0, 0], array_column($bag['nodes'], 'available'));

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
            ],
        ], $bag['nodes']);
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

        $this->assertSame([[
            'item' => 'basket',
            'label' => 'basket',
            'recipe' => ['fibre' => 4],
            'buildable' => false,
        ]], $bag['recipes']);
    }

    /** `buildable` flips when the materials are actually there. */
    public function test_buildable_flips_when_the_materials_are_there(): void
    {
        $user = User::factory()->create();
        app(MeetNode::class)->handle($user, 'reeds');

        $this->assertFalse($this->bag($user)['recipes'][0]['buildable']);

        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        $this->assertTrue($this->bag($user)['recipes'][0]['buildable']);
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

        $bag = $this->bag($user);

        $keys = [...array_keys($bag)];

        foreach (['items', 'nodes', 'skills', 'recipes'] as $list) {
            foreach ($bag[$list] as $row) {
                $keys = [...$keys, ...array_keys($row)];
            }
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
        $this->assertSame([false, false], array_column($bag['nodes'], 'met'));
        $this->assertSame([0, 0], array_column($bag['nodes'], 'available'));
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
}
