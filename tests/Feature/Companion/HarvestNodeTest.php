<?php

namespace Tests\Feature\Companion;

use App\Actions\HarvestNode;
use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Harvesting: stock moves from the world into the bag, bounded by what Blob
 * can still carry.
 *
 * The invariant every case here circles: NOTHING IS EVER DESTROYED. What will
 * not fit stays standing where it was, which is both why a full bag is a
 * reason to build the next container rather than a punishment, and why the
 * "nothing regresses" rule survives having a consumption mechanic at all.
 */
class HarvestNodeTest extends TestCase
{
    use RefreshDatabase;

    /** A companion with the skill, and `$available` units standing at the node. */
    private function clearing(int $available, string $skill = 'gather-fibre', string $node = 'reeds'): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => $skill, 'learned_at' => now()]);
        $companion->nodes()->create(['node' => $node, 'available' => $available]);

        return [$user, $companion];
    }

    private function held(Companion $companion, string $item): int
    {
        return (int) $companion->items()->where('item', $item)->value('quantity');
    }

    private function standing(Companion $companion, string $node): int
    {
        return (int) $companion->nodes()->where('node', $node)->value('available');
    }

    public function test_harvesting_moves_the_standing_stock_into_the_bag(): void
    {
        [$user, $companion] = $this->clearing(3);

        $moved = app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(3, $moved);
        $this->assertSame(3, $this->held($companion, 'fibre'));
        $this->assertSame(0, $this->standing($companion, 'reeds'));
    }

    /**
     * The one that matters. Base capacity is 5, nine units are standing, so
     * five move and FOUR REMAIN STANDING — written as a remainder rather than
     * as "the bag is full", because the remainder is the assertion.
     */
    public function test_harvesting_stops_at_capacity_and_leaves_the_rest_standing(): void
    {
        [$user, $companion] = $this->clearing(9);

        $moved = app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(5, $moved);
        $this->assertSame(5, $this->held($companion, 'fibre'));
        $this->assertSame(4, $this->standing($companion, 'reeds'));
    }

    /** Harvesting again adds to the stack rather than starting a second one. */
    public function test_harvesting_twice_stacks_rather_than_duplicating(): void
    {
        [$user, $companion] = $this->clearing(2);

        app(HarvestNode::class)->handle($user, 'reeds');

        $companion->nodes()->where('node', 'reeds')->update(['available' => 2]);

        app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertDatabaseCount('companion_items', 1);
    }

    /**
     * A full bag refuses, says where the thing stayed, and touches nothing. Not
     * an error on the user's part — a reason to build the next container.
     */
    public function test_a_full_bag_refuses_and_destroys_nothing(): void
    {
        [$user, $companion] = $this->clearing(6);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 5]);

        try {
            app(HarvestNode::class)->handle($user, 'reeds');
            $this->fail('A full bag should have refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('reeds', $exception->getMessage());
        }

        $this->assertSame(5, $this->held($companion, 'fibre'));
        $this->assertSame(6, $this->standing($companion, 'reeds'));
    }

    /** A container raises capacity, so the next harvest takes five more. */
    public function test_a_built_container_lets_the_next_harvest_take_more(): void
    {
        [$user, $companion] = $this->clearing(12);

        $this->assertSame(5, app(HarvestNode::class)->handle($user, 'reeds'));

        // A basket, which is what those five fibre would have built.
        $companion->items()->create(['item' => 'basket', 'quantity' => 1]);

        $this->assertSame(5, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertSame(10, $this->held($companion, 'fibre'));
        $this->assertSame(2, $this->standing($companion, 'reeds'));
    }

    /** No skill, no harvest — and the encounter row alone is not permission. */
    public function test_harvesting_without_the_skill_is_refused(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 4]);

        $this->expectException(CompanionEconomyException::class);

        try {
            app(HarvestNode::class)->handle($user, 'reeds');
        } finally {
            $this->assertSame(4, $this->standing($companion, 'reeds'));
            $this->assertDatabaseCount('companion_items', 0);
        }
    }

    /** An empty node is not an error and not a refusal: nothing moved. */
    public function test_harvesting_an_empty_node_moves_nothing_and_says_so(): void
    {
        [$user, $companion] = $this->clearing(0);

        $this->assertSame(0, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertDatabaseCount('companion_items', 0);
        $this->assertSame(0, $this->standing($companion, 'reeds'));
    }

    /** A node Blob has never met has nothing standing and nothing to take. */
    public function test_harvesting_a_node_never_met_moves_nothing(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->assertSame(0, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertDatabaseCount('companion_items', 0);
    }

    public function test_a_node_nobody_authored_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(HarvestNode::class)->handle(User::factory()->create(), 'the-moon');
    }

    /** Each node yields its own material. */
    public function test_deadfall_yields_deadfall_rather_than_fibre(): void
    {
        [$user, $companion] = $this->clearing(2, 'gather-wood', 'deadfall');

        app(HarvestNode::class)->handle($user, 'deadfall');

        $this->assertSame(2, $this->held($companion, 'deadfall'));
        $this->assertSame(0, $this->held($companion, 'fibre'));
    }

    /** One person's clearing is never harvested into another's bag. */
    public function test_another_users_clearing_is_untouched(): void
    {
        [$user, $companion] = $this->clearing(3);
        [, $strangerCompanion] = $this->clearing(3);

        app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(0, $this->standing($companion, 'reeds'));
        $this->assertSame(3, $this->standing($strangerCompanion, 'reeds'));
        $this->assertSame(0, $this->held($strangerCompanion, 'fibre'));
    }

    /**
     * A node may require a tool as well as a skill. The two gates are the two
     * economic layers meeting on one gesture: the record says what Blob CAN
     * do, and the world says what it has in hand. (F2 §2)
     */
    public function test_a_node_that_needs_a_tool_refuses_without_it(): void
    {
        config()->set('companion.nodes.reeds.tool', 'axe');

        [$user, $companion] = $this->clearing(4);

        try {
            app(HarvestNode::class)->handle($user, 'reeds');

            $this->fail('Harvesting without the tool should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('axe', $exception->getMessage());
        }

        // Nothing moved and nothing was destroyed: the stock is still standing
        // and the bag is still empty.
        $this->assertSame(4, $this->standing($companion, 'reeds'));
        $this->assertSame(0, $this->held($companion, 'fibre'));
    }

    /** With the tool in the bag, the same click gathers as it always did. */
    public function test_a_node_that_needs_a_tool_yields_once_it_is_held(): void
    {
        config()->set('companion.nodes.reeds.tool', 'axe');

        [$user, $companion] = $this->clearing(4);
        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $moved = app(HarvestNode::class)->handle($user, 'reeds');

        $this->assertSame(4, $moved);
        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertSame(0, $this->standing($companion, 'reeds'));
    }

    /**
     * The skill is checked first. A node whose skill has not been bought is a
     * node whose tool is not yet the problem, and refusing for the further of
     * the two reasons would send the reader past the nearer one.
     */
    public function test_the_skill_is_refused_before_the_tool(): void
    {
        config()->set('companion.nodes.reeds.tool', 'axe');

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 4]);

        try {
            app(HarvestNode::class)->handle($user, 'reeds');

            $this->fail('Harvesting without the skill should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('gather-fibre', $exception->getMessage());
            $this->assertStringNotContainsString('axe', $exception->getMessage());
        }
    }

    /** A node with no tool key behaves exactly as F1's two always have. */
    public function test_a_node_with_no_tool_needs_none(): void
    {
        [$user, $companion] = $this->clearing(2);

        $this->assertSame(2, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertSame(2, $this->held($companion, 'fibre'));
    }

    /**
     * The choice this adds, and the reason for it: `HarvestNode` force-fills
     * the bag, which is what springs the trap a bag full of one material is.
     * Asking for less is the deliberate act; asking for nothing in particular
     * is still what every click in the clearing does.
     */
    public function test_harvesting_takes_only_what_was_asked_for(): void
    {
        [$user, $companion] = $this->clearing(4);

        $moved = app(HarvestNode::class)->handle($user, 'reeds', 2);

        $this->assertSame(2, $moved);
        $this->assertSame(2, $this->held($companion->fresh()->load('items'), 'fibre'));
        $this->assertSame(2, $this->standing($companion->fresh(), 'reeds'));
    }

    /** Asking for more than is standing takes what is there, and does not fail. */
    public function test_asking_for_more_than_is_standing_takes_what_is_there(): void
    {
        [$user, $companion] = $this->clearing(2);

        $moved = app(HarvestNode::class)->handle($user, 'reeds', 5);

        $this->assertSame(2, $moved);
        $this->assertSame(0, $this->standing($companion->fresh(), 'reeds'));
    }

    /** And asking for more than fits takes what fits. The rest stays standing. */
    public function test_asking_for_more_than_fits_takes_what_fits(): void
    {
        [$user, $companion] = $this->clearing(10);

        $moved = app(HarvestNode::class)->handle($user, 'reeds', 8);

        $this->assertSame(5, $moved);
        $this->assertSame(5, $this->standing($companion->fresh(), 'reeds'));
    }

    /** Absent means fill, so nothing about the existing gesture changes. */
    public function test_asking_for_nothing_in_particular_still_fills_the_bag(): void
    {
        [$user, $companion] = $this->clearing(10);

        $this->assertSame(5, app(HarvestNode::class)->handle($user, 'reeds'));
        $this->assertSame(5, $this->standing($companion->fresh(), 'reeds'));
    }
}
