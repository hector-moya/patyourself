<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildItem;
use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Building, which is where the "nothing regresses" rule meets a consumption
 * mechanic and survives it.
 *
 * Materials TRANSFORM; they are never taken. Spending four fibre on a basket
 * is not losing four fibre, it is the basket having four fibre in it. People
 * value what they built, which is the whole reason the arc ends in a shelter
 * rather than a threshold.
 *
 * Building needs no skill: assembling by hand is what hands are for.
 */
class BuildItemTest extends TestCase
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

    public function test_building_consumes_exactly_the_recipe(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 6]);

        $basket = app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame('basket', $basket->item);
        $this->assertSame(1, $basket->quantity);
        $this->assertSame(2, $this->held($companion, 'fibre'));
    }

    /** A stack spent down to nothing is removed rather than left at zero. */
    public function test_a_stack_spent_to_nothing_leaves_no_empty_row(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(0, $companion->items()->where('item', 'fibre')->count());
        $this->assertSame(1, $companion->items()->where('item', 'basket')->count());
    }

    /** Building raises capacity, and the built thing does not occupy it. */
    public function test_building_a_container_raises_capacity_without_taking_room(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        $this->assertSame(5, $companion->capacity());

        app(BuildItem::class)->handle($user, 'basket');

        $fresh = $companion->fresh()->load('items');

        $this->assertSame(10, $fresh->capacity());
        $this->assertSame(0, $fresh->held());
        $this->assertSame(10, $fresh->room());
    }

    /** Each container adds five, cumulative. */
    public function test_two_baskets_add_ten(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 8]);

        app(BuildItem::class)->handle($user, 'basket');
        app(BuildItem::class)->handle($user, 'basket');

        $fresh = $companion->fresh()->load('items');

        $this->assertSame(2, $this->held($companion, 'basket'));
        $this->assertSame(1, $companion->items()->where('item', 'basket')->count());
        $this->assertSame(15, $fresh->capacity());
    }

    /**
     * A short recipe refuses, names the whole shortfall at once rather than the
     * first miss, and consumes nothing.
     */
    public function test_a_short_recipe_refuses_and_consumes_nothing(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 3]);

        try {
            app(BuildItem::class)->handle($user, 'basket');
            $this->fail('A short recipe should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('basket', $exception->getMessage());
            $this->assertStringContainsString('1 fibre', $exception->getMessage());
        }

        $this->assertSame(3, $this->held($companion, 'fibre'));
        $this->assertSame(0, $companion->items()->where('item', 'basket')->count());
    }

    /** Carrying none of an ingredient is a shortfall like any other. */
    public function test_carrying_none_of_an_ingredient_refuses(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 8]);

        $this->expectException(CompanionEconomyException::class);

        try {
            app(BuildItem::class)->handle($user, 'barrow');
        } finally {
            $this->assertSame(8, $this->held($companion, 'fibre'));
        }
    }

    /** Building needs no skill: hands are enough. */
    public function test_building_needs_no_skill(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        $this->assertSame(0, $companion->skills()->count());

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(1, $this->held($companion, 'basket'));
    }

    /** The two forks build different things from different materials. */
    public function test_deadfall_builds_a_barrow(): void
    {
        [$user, $companion] = $this->carrying(['deadfall' => 4]);

        app(BuildItem::class)->handle($user, 'barrow');

        $this->assertSame(1, $this->held($companion, 'barrow'));
        $this->assertSame(0, $companion->items()->where('item', 'deadfall')->count());
    }

    public function test_an_item_nobody_authored_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(BuildItem::class)->handle(User::factory()->create(), 'cathedral');
    }

    /** A material is not a recipe: there is nothing to build fibre out of. */
    public function test_an_item_with_no_recipe_is_rejected(): void
    {
        [$user] = $this->carrying(['fibre' => 8]);

        $this->expectException(InvalidArgumentException::class);

        app(BuildItem::class)->handle($user, 'fibre');
    }

    /** The consumption and the built row are one write or neither. */
    public function test_the_consumption_and_the_build_are_written_together(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        DB::beginTransaction();
        app(BuildItem::class)->handle($user, 'basket');
        DB::rollBack();

        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertSame(0, $companion->items()->where('item', 'basket')->count());
    }

    /**
     * The second half of F2's rule: a recipe may ask for a tool. It never asks
     * for a skill — see test_building_needs_no_skill, which stays exactly as
     * it is, because the basket still needs none.
     */
    public function test_a_recipe_that_needs_a_tool_refuses_without_it(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        [$user, $companion] = $this->carrying(['fibre' => 4]);

        try {
            app(BuildItem::class)->handle($user, 'basket');

            $this->fail('Building without the tool should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('handsaw', $exception->getMessage());
        }

        // Materials transform; they are never taken. A refusal takes nothing.
        $this->assertSame(4, $this->held($companion, 'fibre'));
        $this->assertSame(0, $companion->items()->where('item', 'basket')->count());
    }

    /** With the tool held, the recipe builds as it always did. */
    public function test_a_recipe_that_needs_a_tool_builds_once_it_is_held(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        [$user, $companion] = $this->carrying(['fibre' => 4, 'handsaw' => 1]);

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(1, $this->held($companion, 'basket'));
        // The tool is used, never used UP. Nothing here wears out.
        $this->assertSame(1, $this->held($companion, 'handsaw'));
    }

    /**
     * The tool is named before the materials. It is the harder of the two to
     * come by, and naming the nearer obstacle first would send the reader back
     * for fibre they still could not use.
     */
    public function test_the_tool_is_named_before_the_shortfall(): void
    {
        config()->set('companion.bag.basket.tool', 'handsaw');

        [$user] = $this->carrying(['fibre' => 1]);

        try {
            app(BuildItem::class)->handle($user, 'basket');

            $this->fail('Building should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('handsaw', $exception->getMessage());
            $this->assertStringNotContainsString('fibre', $exception->getMessage());
        }
    }

    /** One timber, three planks. The count is config's to say. */
    public function test_a_recipe_can_make_more_than_one(): void
    {
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 3,
        ]);

        [$user, $companion] = $this->carrying(['fibre' => 2]);

        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(3, $this->held($companion, 'planks'));
        $this->assertSame(1, $this->held($companion, 'fibre'));
    }

    /** Building the same thing twice stacks what it makes. */
    public function test_making_several_twice_stacks_them(): void
    {
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 3,
        ]);
        config()->set('companion.capacity.base', 20);

        [$user, $companion] = $this->carrying(['fibre' => 2]);

        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(6, $this->held($companion, 'planks'));
        $this->assertSame(1, $companion->items()->where('item', 'planks')->count());
    }

    /** A recipe that does not say makes one, exactly as every F1 recipe does. */
    public function test_a_recipe_with_no_count_makes_one(): void
    {
        [$user, $companion] = $this->carrying(['fibre' => 4]);

        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(1, $this->held($companion, 'basket'));
    }

    /**
     * A recipe that says it makes nothing still makes one.
     *
     * The floor matters more than it looks. Without it a single mistyped
     * digit in config would consume the whole recipe and hand back nothing —
     * the one way this feature could ever take something away, and it would
     * do it silently. Everything else here is guarded against that; this is
     * the line that guards the guard.
     */
    public function test_a_recipe_that_says_it_makes_nothing_still_makes_one(): void
    {
        // The whole entry, not a sub-key: `planks` is not authored yet, and
        // setting `…planks.makes` alone would leave it without a recipe to
        // build from.
        config()->set('companion.bag.planks', [
            'category' => 'material',
            'label' => 'planks',
            'recipe' => ['fibre' => 1],
            'makes' => 0,
        ]);

        [$user, $companion] = $this->carrying(['fibre' => 2]);

        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(1, $this->held($companion, 'planks'));

        // And it really did build rather than no-op: the fibre was spent.
        $this->assertSame(1, $this->held($companion, 'fibre'));
    }
}
