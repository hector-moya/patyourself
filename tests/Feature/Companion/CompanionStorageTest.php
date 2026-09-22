<?php

namespace Tests\Feature\Companion;

use App\Models\Companion;
use App\Models\CompanionStashItem;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The four tables F1 adds, and the one rule that governs all of them: store
 * only what cannot be derived.
 *
 * Counts, earned XP and the gift ladder are absent here on purpose. They stay
 * derived from the record on every read, so the parts that could drift still
 * cannot — see {@see CompanionResolver}.
 */
class CompanionStorageTest extends TestCase
{
    use RefreshDatabase;

    /** Nothing is backfilled; the row appears the first time anything needs it. */
    public function test_a_companion_row_is_made_lazily_and_only_once(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->companion);

        $first = $user->companion()->firstOrCreate([]);
        $second = $user->fresh()->companion()->firstOrCreate([]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Companion::query()->count());
        $this->assertSame(0, $first->xp_spent);
        $this->assertNull($first->name);
    }

    /** One companion per user, enforced by the database rather than by care. */
    public function test_a_user_cannot_have_two_companions(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([]);

        $this->expectException(QueryException::class);

        Companion::factory()->create(['user_id' => $user->id]);
    }

    /** Null reads as "Blob", so nothing changes until someone renames. */
    public function test_an_unnamed_companion_is_called_blob(): void
    {
        $companion = Companion::factory()->create(['name' => null]);

        $this->assertSame('Blob', $companion->displayName());

        $companion->update(['name' => 'Pebble']);

        $this->assertSame('Pebble', $companion->displayName());
    }

    /** Clearing the name is not destructive: it falls back, it does not break. */
    public function test_a_name_of_whitespace_reads_as_blob_again(): void
    {
        $companion = Companion::factory()->named('Pebble')->create();

        $companion->update(['name' => '   ']);

        $this->assertSame('Blob', $companion->displayName());
    }

    /** A skill is learned once. The unique index is what makes that true. */
    public function test_a_skill_is_learned_at_most_once(): void
    {
        $companion = Companion::factory()->create();

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->expectException(QueryException::class);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
    }

    /** One stack per item, so a quantity is incremented rather than duplicated. */
    public function test_the_bag_holds_one_stack_per_item(): void
    {
        $companion = Companion::factory()->create();

        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $this->expectException(QueryException::class);

        $companion->items()->create(['item' => 'fibre', 'quantity' => 1]);
    }

    /** One row per node, likewise. */
    public function test_the_world_holds_one_row_per_node(): void
    {
        $companion = Companion::factory()->create();

        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        $this->expectException(QueryException::class);

        $companion->nodes()->create(['node' => 'reeds', 'available' => 1]);
    }

    /**
     * Base capacity is Blob's hands. Nothing held, nothing built, so five —
     * and the config keys the bag reads are not there yet, which is why the
     * defaults have to be the right numbers rather than zero.
     */
    public function test_an_empty_bag_holds_nothing_against_a_capacity_of_five(): void
    {
        $companion = Companion::factory()->create();

        $this->assertSame(5, $companion->capacity());
        $this->assertSame(0, $companion->held());
        $this->assertSame(5, $companion->room());
    }

    /** Deleting the account takes the whole clearing with it. */
    public function test_everything_cascades_when_the_user_goes(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        $user->delete();

        $this->assertDatabaseCount('companions', 0);
        $this->assertDatabaseCount('companion_skills', 0);
        $this->assertDatabaseCount('companion_items', 0);
        $this->assertDatabaseCount('companion_nodes', 0);
    }

    /**
     * The shelter is the one stored value in this feature that only ever moves
     * one way. It is a single value rather than a collection because the stages
     * REPLACE one another — building the hut is the lean-to becoming a hut, and
     * the lean-to's planks are in it.
     */
    public function test_the_companion_row_can_hold_a_shelter(): void
    {
        $this->assertTrue(Schema::hasColumn('companions', 'shelter'));

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $this->assertNull($companion->shelter);

        $companion->update(['shelter' => 'lean-to']);

        $this->assertSame('lean-to', $companion->fresh()->shelter);
    }

    /**
     * And a mark saying its cabin was already converted into a heap.
     *
     * Stored rather than derived because the heap is DELETED once it is
     * drained, so "is there a salvage node?" cannot answer "has this account
     * been converted?" — and an unmarked account would be handed a second
     * cabin's worth of planks the next time the conversion ran.
     */
    public function test_the_companion_row_remembers_a_cabin_it_already_gave_back(): void
    {
        $this->assertTrue(Schema::hasColumn('companions', 'salvaged_at'));

        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $this->assertNull($companion->salvaged_at);

        $companion->forceFill(['salvaged_at' => now()])->save();

        $this->assertInstanceOf(CarbonInterface::class, $companion->fresh()->salvaged_at);
    }

    /**
     * THE REASON THIS IS ITS OWN TABLE.
     *
     * `Companion::held()` and `Companion::capacity()` both sum `$this->items`
     * with no filter. Had the stash been a `location` column on
     * `companion_items`, a stashed crate would raise carry capacity and
     * stashed planks would count against the bag — silently, with every
     * existing test green, because every existing test writes rows that are
     * all in one place.
     *
     * The mutation that turns this red is pointing `stashItems()` at
     * `companion_items`.
     */
    public function test_what_is_at_home_is_neither_carried_nor_capacity(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);
        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 9]);
        $companion->stashItems()->create(['item' => 'crate', 'quantity' => 1]);

        $companion->load('items');

        $this->assertSame(2, $companion->held());
        $this->assertSame(5, $companion->capacity());
        $this->assertSame(2, $companion->stashItems()->count());
    }

    /** One stack per item at home, enforced by the database rather than by care. */
    public function test_the_stash_cannot_hold_two_stacks_of_one_item(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 1]);

        $this->expectException(QueryException::class);

        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 1]);
    }

    /** The stash goes with the companion it belongs to. */
    public function test_the_stash_is_removed_with_its_companion(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 3]);

        $companion->delete();

        $this->assertSame(0, CompanionStashItem::query()->count());
    }
}
