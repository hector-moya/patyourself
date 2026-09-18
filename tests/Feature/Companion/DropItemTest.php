<?php

namespace Tests\Feature\Companion;

use App\Actions\DropItem;
use App\Models\Companion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The one place in this feature that destroys something.
 *
 * It exists because F2 left a real dead end: a bag at capacity holding timber
 * and no rope can build nothing, and the world keeps accruing while you are
 * away, so the trap gets MORE likely the longer someone is gone.
 *
 * This is not "nothing regresses" breaking. Every prohibition in that rule
 * names something the system does TO you — decay, expiry, upkeep, removal for
 * inactivity. A player choosing to tip out timber is the same category as
 * choosing to spend fibre on a basket: the player spends, and worlds diverge.
 * The line that must hold is that destruction is always player-initiated and
 * never automatic, and {@see CompanionRulingsTest} is where that is guarded.
 */
class DropItemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function carrying(array $items): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        foreach ($items as $item => $quantity) {
            $companion->items()->create(['item' => $item, 'quantity' => $quantity]);
        }

        return [$user, $companion];
    }

    private function held(Companion $companion, string $item): int
    {
        return (int) $companion->items()->where('item', $item)->value('quantity');
    }

    /** The whole stack, and only that stack. */
    public function test_dropping_destroys_the_whole_stack(): void
    {
        [$user, $companion] = $this->carrying(['timber' => 3, 'fibre' => 2]);

        $dropped = app(DropItem::class)->handle($user, 'timber');

        $this->assertSame(3, $dropped);
        $this->assertSame(0, $companion->items()->where('item', 'timber')->count());
        $this->assertSame(2, $this->held($companion, 'fibre'));
    }

    /**
     * It is destroyed, not put back. Returning it to the node was considered
     * and rejected: it would make the bag a lossless scratchpad and quietly
     * remove the weight from every decision to pick something up.
     */
    public function test_dropping_never_touches_node_stock(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 6]);

        app(DropItem::class)->handle($user, 'fibre');

        $this->assertSame(6, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }

    /** And it frees exactly that much room. */
    public function test_dropping_frees_the_room_it_took(): void
    {
        [$user, $companion] = $this->carrying(['timber' => 5]);

        $this->assertSame(0, $companion->fresh()->load('items')->room());

        app(DropItem::class)->handle($user, 'timber');

        $this->assertSame(5, $companion->fresh()->load('items')->room());
    }

    /** Dropping what is not held is not a failure. There was nothing there. */
    public function test_dropping_something_not_held_takes_nothing(): void
    {
        [$user] = $this->carrying(['fibre' => 1]);

        $this->assertSame(0, app(DropItem::class)->handle($user, 'timber'));
    }

    /**
     * A tool is on the belt and takes no room, so tipping one out buys nothing
     * and costs something permanent. The overturn in spec §2 was argued on the
     * grounds that the player SPENDS; a destruction that gains nothing is not
     * spending, and letting it through would put the only permanent thing in
     * the feature one misclick from gone.
     */
    public function test_a_tool_is_not_something_to_tip_out(): void
    {
        [$user] = $this->carrying(['axe' => 1]);

        $this->expectException(InvalidArgumentException::class);

        app(DropItem::class)->handle($user, 'axe');
    }

    /** Nor a container: dropping one would lower capacity, which is regression. */
    public function test_a_container_is_not_something_to_tip_out(): void
    {
        [$user] = $this->carrying(['basket' => 1]);

        $this->expectException(InvalidArgumentException::class);

        app(DropItem::class)->handle($user, 'basket');
    }

    public function test_an_item_nobody_authored_is_rejected(): void
    {
        [$user] = $this->carrying([]);

        $this->expectException(InvalidArgumentException::class);

        app(DropItem::class)->handle($user, 'anvil');
    }

    /** One person's bag is never another's. */
    public function test_another_users_bag_is_untouched(): void
    {
        [$user] = $this->carrying(['fibre' => 2]);
        [, $strangersCompanion] = $this->carrying(['fibre' => 4]);

        app(DropItem::class)->handle($user, 'fibre');

        $this->assertSame(4, $this->held($strangersCompanion, 'fibre'));
    }
}
