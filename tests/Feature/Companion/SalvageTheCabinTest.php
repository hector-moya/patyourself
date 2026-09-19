<?php

namespace Tests\Feature\Companion;

use App\Actions\SalvageTheCabin;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cabin the record used to grant, handed back as what it was worth.
 *
 * `insights: 5` has stopped granting a cabin and started being the floor at
 * which one may be built. An established account must not simply lose what it
 * had — so the cabin COMES APART, into a heap standing in its own clearing,
 * and putting it back up becomes the first thing there is to do.
 *
 * The materials go into the world rather than into the bag because they cannot
 * fit in a bag: the whole arc costs more planks than any container this game
 * can build will hold at once. A heap is the rule "the world holds the
 * overflow" doing exactly the job it was written for.
 */
class SalvageTheCabinTest extends TestCase
{
    use RefreshDatabase;

    private function withInsights(User $user, int $count): User
    {
        for ($index = 0; $index < $count; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }

        return $user;
    }

    private function heap(User $user): ?int
    {
        $available = $user->companion()->first()?->nodes()
            ->where('node', 'salvage')
            ->value('available');

        return $available === null ? null : (int) $available;
    }

    public function test_a_record_past_the_floor_gets_its_cabin_back_as_a_heap(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);

        $this->assertSame(1, app(SalvageTheCabin::class)->handle());

        $this->assertSame(26, $this->heap($user));
        $this->assertNotNull($user->companion()->first()->salvaged_at);
    }

    /**
     * Below the floor, nothing happens — and specifically, no companion row is
     * written. Looking at an account must not create one: the row is the
     * chosen half of Blob, and nothing has been chosen.
     */
    public function test_a_record_below_the_floor_is_untouched(): void
    {
        $user = $this->withInsights(User::factory()->create(), 4);

        $this->assertSame(0, app(SalvageTheCabin::class)->handle());

        $this->assertNull($user->companion()->first());
        $this->assertDatabaseCount('companions', 0);
    }

    /** Running it twice grants one cabin, not two. */
    public function test_it_is_idempotent(): void
    {
        $user = $this->withInsights(User::factory()->create(), 6);

        app(SalvageTheCabin::class)->handle();

        $this->assertSame(0, app(SalvageTheCabin::class)->handle());
        $this->assertSame(26, $this->heap($user));
    }

    /**
     * The case the mark exists for, and the reason it cannot be derived: once
     * the heap is drained it is DELETED, so its own absence cannot tell a
     * converted account from an unconverted one.
     */
    public function test_a_drained_heap_is_not_granted_a_second_time(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);

        app(SalvageTheCabin::class)->handle();

        $user->companion()->first()->nodes()->where('node', 'salvage')->delete();

        $this->assertSame(0, app(SalvageTheCabin::class)->handle());
        $this->assertNull($this->heap($user));
    }

    /** A heap already standing is left exactly as it is, not topped back up. */
    public function test_a_partly_drawn_heap_is_left_alone(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);

        app(SalvageTheCabin::class)->handle();

        $user->companion()->first()->nodes()->where('node', 'salvage')->update(['available' => 9]);

        app(SalvageTheCabin::class)->handle();

        $this->assertSame(9, $this->heap($user));
    }

    /** It converts every qualifying account and only those. */
    public function test_it_converts_every_qualifying_account_and_no_others(): void
    {
        $deep = $this->withInsights(User::factory()->create(), 7);
        $alsoDeep = $this->withInsights(User::factory()->create(), 5);
        $shallow = $this->withInsights(User::factory()->create(), 2);

        $this->assertSame(2, app(SalvageTheCabin::class)->handle());

        $this->assertSame(26, $this->heap($deep));
        $this->assertSame(26, $this->heap($alsoDeep));
        $this->assertNull($this->heap($shallow));
    }

    /** Nothing about what Blob already had is disturbed by the conversion. */
    public function test_the_conversion_takes_nothing(): void
    {
        $user = $this->withInsights(User::factory()->create(), 5);
        $companion = $user->companion()->firstOrCreate([]);
        $companion->update(['name' => 'Pebble', 'xp_spent' => 40]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 7]);

        app(SalvageTheCabin::class)->handle();

        $fresh = $companion->fresh();

        $this->assertSame('Pebble', $fresh->name);
        $this->assertSame(40, $fresh->xp_spent);
        $this->assertSame(3, (int) $fresh->items()->where('item', 'fibre')->value('quantity'));
        $this->assertSame(7, (int) $fresh->nodes()->where('node', 'reeds')->value('available'));
        $this->assertSame(1, $fresh->skills()->count());
    }
}
