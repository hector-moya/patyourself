<?php

namespace Tests\Feature\Companion;

use App\Models\Companion;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
