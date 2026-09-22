<?php

namespace Tests\Feature\Companion;

use App\Actions\HarvestNode;
use App\Actions\UnstashItem;
use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Fetching something back out of the chest.
 *
 * BOUNDED BY WHAT BLOB CAN STILL CARRY, AND THE REMAINDER STAYS AT HOME —
 * the same promise {@see HarvestNode} makes about a node, seen
 * from the other side. A full bag refuses and takes nothing; anything else
 * moves what fits.
 *
 * This is why the chest does not remove the need for containers: the stash can
 * hold the twenty-six planks a cabin's arc costs, and the bag still decides how
 * many of them can be in hand at once.
 */
class UnstashItemTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function withStash(array $atHome, array $carried = []): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        foreach ($atHome as $item => $quantity) {
            $companion->stashItems()->create(['item' => $item, 'quantity' => $quantity]);
        }

        foreach ($carried as $item => $quantity) {
            $companion->items()->create(['item' => $item, 'quantity' => $quantity]);
        }

        return [$user, $companion];
    }

    public function test_a_named_amount_comes_back_and_the_rest_stays_at_home(): void
    {
        [$user, $companion] = $this->withStash(['planks' => 9]);

        $moved = app(UnstashItem::class)->handle($user, 'planks', 4);

        $this->assertSame(4, $moved);
        $this->assertSame(4, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertSame(5, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    /**
     * The clamp that is the whole reason capacity survives this phase.
     *
     * Twenty-six planks at home, a base bag, and one gesture: five come back
     * and twenty-one are still in the chest.
     */
    public function test_a_withdrawal_never_exceeds_what_the_bag_can_hold(): void
    {
        [$user, $companion] = $this->withStash(['planks' => 26]);

        $moved = app(UnstashItem::class)->handle($user, 'planks', 26);

        $this->assertSame(5, $moved);
        $this->assertSame(5, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertSame(21, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));

        $companion->load('items');

        $this->assertSame(5, $companion->held());
    }

    /** A full bag refuses, and the chest is untouched by the refusal. */
    public function test_a_full_bag_refuses_and_moves_nothing(): void
    {
        [$user, $companion] = $this->withStash(['planks' => 9], ['fibre' => 5]);

        try {
            app(UnstashItem::class)->handle($user, 'planks', 1);
            $this->fail('A full bag should refuse.');
        } catch (CompanionEconomyException) {
            // The refusal is the assertion; what matters is what it did not do.
        }

        $this->assertSame(9, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
        $this->assertSame(0, $companion->items()->where('item', 'planks')->count());
    }

    /** A stack fetched back in full leaves no empty row at home. */
    public function test_a_stack_emptied_leaves_no_row_behind(): void
    {
        [$user, $companion] = $this->withStash(['fibre' => 3]);

        $this->assertSame(3, app(UnstashItem::class)->handle($user, 'fibre'));
        $this->assertSame(0, $companion->stashItems()->where('item', 'fibre')->count());
    }

    /** Nothing at home is an ordinary answer, not a refusal. */
    public function test_nothing_at_home_moves_nothing(): void
    {
        [$user] = $this->withStash([]);

        $this->assertSame(0, app(UnstashItem::class)->handle($user, 'planks'));
    }

    public function test_an_unauthored_item_is_refused(): void
    {
        [$user] = $this->withStash([]);

        $this->expectException(InvalidArgumentException::class);

        app(UnstashItem::class)->handle($user, 'moonstone');
    }
}
