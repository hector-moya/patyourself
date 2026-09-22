<?php

namespace Tests\Feature\Companion;

use App\Actions\StashItem;
use App\Models\Companion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Putting something down at home.
 *
 * The stash is UNCAPPED, so this action has no refusal of its own: the world
 * is uncapped everywhere else in this feature and a ceiling here would be the
 * first place it says no. What it does have is the same category rule the bag
 * already enforces — only what Blob CARRIES can be put down, because a tool is
 * on the belt and a container is the room itself.
 */
class StashItemTest extends TestCase
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

    public function test_a_named_amount_moves_and_the_rest_stays_carried(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 3]);

        $moved = app(StashItem::class)->handle($user, 'planks', 2);

        $this->assertSame(2, $moved);
        $this->assertSame(1, (int) $companion->items()->where('item', 'planks')->value('quantity'));
        $this->assertSame(2, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    /** No amount means the whole stack: putting something down is not a measured act. */
    public function test_no_amount_moves_the_whole_stack(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        $moved = app(StashItem::class)->handle($user, 'fibre');

        $this->assertSame(4, $moved);
        $this->assertSame(0, $companion->items()->where('item', 'fibre')->count());
        $this->assertSame(4, (int) $companion->stashItems()->where('item', 'fibre')->value('quantity'));
    }

    /** Asking for more than is carried moves what is carried, and says so. */
    public function test_asking_for_more_than_is_held_moves_what_is_held(): void
    {
        [$user, $companion] = $this->carrying(['timber' => 2]);

        $this->assertSame(2, app(StashItem::class)->handle($user, 'timber', 9));
        $this->assertSame(0, $companion->items()->where('item', 'timber')->count());
    }

    /** Stacks merge at home rather than sitting beside each other. */
    public function test_a_second_deposit_adds_to_the_stack_already_there(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 2]);

        app(StashItem::class)->handle($user, 'planks', 1);
        app(StashItem::class)->handle($user, 'planks', 1);

        $this->assertSame(1, $companion->stashItems()->where('item', 'planks')->count());
        $this->assertSame(2, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    /** Nothing there is an ordinary answer, not a refusal. */
    public function test_nothing_carried_moves_nothing_and_says_nothing(): void
    {
        [$user, $companion] = $this->carrying([]);

        $this->assertSame(0, app(StashItem::class)->handle($user, 'planks'));
        $this->assertSame(0, $companion->stashItems()->count());
    }

    /** A tool is on the belt; a container is the room. Neither is put down. */
    public function test_only_carried_categories_may_be_put_down(): void
    {
        [$user] = $this->carrying(['axe' => 1, 'crate' => 1]);

        $this->expectException(InvalidArgumentException::class);

        app(StashItem::class)->handle($user, 'axe');
    }

    public function test_an_unauthored_item_is_refused(): void
    {
        [$user] = $this->carrying([]);

        $this->expectException(InvalidArgumentException::class);

        app(StashItem::class)->handle($user, 'moonstone');
    }

    /**
     * THE STASH IS UNCAPPED, by design: the world is uncapped everywhere else
     * in this feature — node stock has no ceiling and nothing expires — and a
     * ceiling here would be the first place it refuses to hold something. This
     * is the test the spec's own mutation table names for that rule (§12,
     * "The stash is uncapped" / "add a ceiling") and it exists to notice if a
     * ceiling is ever introduced: a large deposit, made in one call, must move
     * in full.
     */
    public function test_a_large_deposit_moves_in_full(): void
    {
        [$user, $companion] = $this->carrying(['planks' => 30]);

        $moved = app(StashItem::class)->handle($user, 'planks');

        $this->assertSame(30, $moved);
        $this->assertSame(30, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }
}
