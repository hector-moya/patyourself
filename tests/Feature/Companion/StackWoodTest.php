<?php

namespace Tests\Feature\Companion;

use App\Actions\StackWood;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StackWoodTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Two different guards, and a gap neither one covers.
     *
     * `$companion->woodpile` proves `Companion::$attributes` carries the
     * default — delete `'woodpile' => 0` from that array and this line reads
     * null instead of 0. `$user->companion()->value('woodpile')` proves the
     * row is readable and the column is real — comment out the migration's
     * `Schema::table` call and the insert errors before either line runs.
     *
     * NEITHER CATCHES A WRONG COLUMN DEFAULT. `getAttributesForInsert()`
     * returns the model's whole in-memory attribute array with no re-fetch,
     * so `create()` and `firstOrCreate()` always send an explicit
     * `woodpile = 0` on insert — the column's own default clause is never
     * consulted on any write path this application has. MEASURED: the
     * migration's `default(0)` changed to `default(5)`, run, and both
     * assertions stayed green. `xp_spent` carries the identical shape and
     * predates this phase, so this is a property of the pattern, not of
     * this column.
     */
    public function test_a_new_companion_starts_with_nothing_stacked(): void
    {
        $user = User::factory()->create();

        $companion = $user->companion()->create([]);

        $this->assertSame(0, $companion->woodpile);
        $this->assertSame(0, (int) $user->companion()->value('woodpile'));
    }

    public function test_stacking_moves_the_whole_stack_into_the_pile(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'deadfall', 'quantity' => 4]);

        $moved = app(StackWood::class)->handle($user, 'deadfall');

        $this->assertSame(4, $moved);
        $this->assertSame(4, $companion->fresh()->woodpile);
        $this->assertSame(0, $companion->items()->where('item', 'deadfall')->count());
    }

    public function test_stacking_an_amount_leaves_the_rest_in_the_bag(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 5]);

        $moved = app(StackWood::class)->handle($user, 'planks', 2);

        $this->assertSame(2, $moved);
        $this->assertSame(2, $companion->fresh()->woodpile);
        $this->assertSame(
            3,
            $companion->items()->where('item', 'planks')->value('quantity'),
        );
    }

    /**
     * A CEILING HAS TWO SHAPES: a clamp that limits this call, and a threshold
     * that refuses once N is already held. A test starting from an empty pile
     * can only ever see the first, so this one starts from a pile that already
     * holds a great deal and stacks onto it.
     */
    public function test_the_pile_takes_more_however_much_is_in_it(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create(['woodpile' => 9_000]);
        $companion->items()->create(['item' => 'timber', 'quantity' => 7]);

        $moved = app(StackWood::class)->handle($user, 'timber');

        $this->assertSame(7, $moved);
        $this->assertSame(9_007, $companion->fresh()->woodpile);
    }

    public function test_the_pile_refuses_what_it_does_not_take(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $this->expectException(\InvalidArgumentException::class);

        app(StackWood::class)->handle($user, 'fibre');
    }

    public function test_stacking_what_is_not_held_moves_nothing(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);

        $moved = app(StackWood::class)->handle($user, 'deadfall');

        $this->assertSame(0, $moved);
        $this->assertSame(0, $companion->fresh()->woodpile);
    }

    public function test_the_bag_loses_exactly_what_the_pile_gains(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->create([]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 6]);

        $before = $companion->fresh()->held();

        $moved = app(StackWood::class)->handle($user, 'planks', 4);

        $this->assertSame(4, $moved);
        $this->assertSame($before - 4, $companion->fresh()->held());
    }
}
