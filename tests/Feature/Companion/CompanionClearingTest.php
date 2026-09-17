<?php

namespace Tests\Feature\Companion;

use App\Http\Controllers\CompanionController;
use App\Models\ActionLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Touching something in the clearing, end to end.
 *
 * One route for both halves of the gesture, because from the user's side it IS
 * one gesture. What happens depends on whether Blob knows what the thing is,
 * and that difference is the whole of F1's discovery mechanic.
 */
class CompanionClearingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** Enough outcomes, on enough days, to clear a 20 xp price. */
    private function richUser(int $days = 8): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $base = CarbonImmutable::parse('2026-07-01T09:00:00+00:00');

        for ($index = 0; $index < $days; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $base->addDays($index),
            ]);
        }

        return $user;
    }

    private function touch(User $user, string $node)
    {
        return $this->actingAs($user)->post(route('companion.nodes.store', $node));
    }

    /**
     * The acceptance criterion for the whole mechanic: clicking a node you
     * cannot use does not fail, does not show a lock, and is what puts the
     * skill in the list.
     */
    public function test_touching_a_node_without_its_skill_reveals_the_skill_and_takes_nothing(): void
    {
        $user = $this->richUser();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page->where('bag.skills', []));

        $this->touch($user, 'reeds')->assertRedirect();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bag.skills.0.skill', 'gather-fibre')
                ->where('bag.skills.0.price', 20)
                ->where('bag.skills.0.known', false)
                // Nothing was gathered: looking at a thing is not taking it.
                ->where('bag.items', [])
                ->where('bag.held', 0),
            );
    }

    /** And it says so, in Blob's own voice, with Blob's own name. */
    public function test_the_encounter_says_what_happened(): void
    {
        $user = $this->richUser();
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'Pebble turns the reeds over and puts them down again.'),
            );
    }

    /**
     * The payment for the bag being a modal. A panel would have made the skill
     * appearing visible; this shows it instead — once, as the response to the
     * click that revealed it.
     */
    public function test_the_first_encounter_opens_the_bag(): void
    {
        $user = $this->richUser();

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page->where('revealed', true));
    }

    /** Meeting a node Blob has already met reveals nothing, so opens nothing. */
    public function test_a_second_encounter_with_the_same_node_opens_nothing(): void
    {
        $user = $this->richUser();

        $this->touch($user, 'reeds');
        $this->actingAs($user)->get(route('companion'));

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page->where('revealed', false));
    }

    /** Nothing opens on an ordinary visit. The flash does not survive one. */
    public function test_nothing_opens_on_a_plain_visit(): void
    {
        $user = $this->richUser();

        $this->touch($user, 'reeds');
        $this->actingAs($user)->get(route('companion'));

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('revealed', false)
                ->where('said', null),
            );
    }

    /** With the skill, the same press gathers instead. */
    public function test_touching_a_node_with_its_skill_gathers_from_it(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bag.items.0.item', 'fibre')
                ->where('bag.items.0.quantity', 3)
                ->where('bag.held', 3)
                ->where('said', 'Blob comes back with 3 fibre.'),
            );
    }

    /**
     * A full bag is a state of the world, not an error. It says where the thing
     * stayed, because it is still standing there.
     */
    public function test_a_full_bag_says_so_and_leaves_the_node_standing(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 6]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 5]);

        $this->touch($user, 'reeds')->assertRedirect();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'There is nowhere to put them. The reeds stay by the water.')
                ->where('bag.held', 5),
            );

        $this->assertSame(6, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }

    /** An empty node is a fact about the clearing, not a refusal. */
    public function test_an_empty_node_says_nothing_has_grown_back(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 0]);

        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'Blob checks the reeds. Nothing has grown back yet.'),
            );
    }

    public function test_a_node_nobody_authored_is_not_found(): void
    {
        $this->touch($this->richUser(), 'the-moon')->assertNotFound();
    }

    public function test_touching_the_clearing_requires_signing_in(): void
    {
        $this->post(route('companion.nodes.store', 'reeds'))->assertRedirect(route('login'));

        $this->assertDatabaseCount('companion_nodes', 0);
    }

    /** Both nodes are in the payload from the very first visit, unmet. */
    public function test_the_whole_clearing_is_visible_from_the_start(): void
    {
        $user = $this->richUser();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bag.nodes.0.node', 'reeds')
                ->where('bag.nodes.0.met', false)
                ->where('bag.nodes.1.node', 'deadfall')
                ->where('bag.nodes.1.met', false),
            );
    }

    /** Buying from inside the bag debits, and leaves the bag open. */
    public function test_buying_a_skill_from_the_bag_leaves_it_open(): void
    {
        $user = $this->richUser();
        $this->touch($user, 'reeds');
        $this->actingAs($user)->get(route('companion'));

        $this->actingAs($user)
            ->post(route('companion.skills.store'), ['skill' => 'gather-fibre'])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::STAY_KEY, true);

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('revealed', true)
                ->where('bag.skills.0.known', true)
                ->where('bag.xp', 4)
                ->where('said', 'Blob has learned to gather fibre.'),
            );
    }

    /** A short balance is a line, not a 500, and nothing is spent. */
    public function test_a_short_balance_is_refused_without_an_error_page(): void
    {
        $user = $this->richUser(2);
        $this->touch($user, 'reeds');

        $this->actingAs($user)
            ->post(route('companion.skills.store'), ['skill' => 'gather-fibre'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bag.skills.0.known', false)
                ->where('bag.xp', 6),
            );

        $this->assertSame(0, (int) $user->companion()->value('xp_spent'));
    }

    public function test_a_skill_nobody_authored_is_rejected(): void
    {
        $this->actingAs($this->richUser())
            ->post(route('companion.skills.store'), ['skill' => 'gather-moonlight'])
            ->assertSessionHasErrors('skill');
    }

    /** Building consumes the recipe and raises capacity, bag still open. */
    public function test_building_from_the_bag_leaves_it_open(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        $this->actingAs($user)
            ->post(route('companion.build.store'), ['item' => 'basket'])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::STAY_KEY, true);

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('revealed', true)
                ->where('bag.capacity', 10)
                ->where('bag.held', 0)
                ->where('said', 'Blob put together a basket.'),
            );
    }

    /** Short materials say so, and consume nothing. */
    public function test_short_materials_are_refused_without_an_error_page(): void
    {
        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.build.store'), ['item' => 'basket'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('said', 'There is not enough for a basket yet.')
                ->where('bag.capacity', 5),
            );

        $this->assertSame(3, (int) $companion->items()->where('item', 'fibre')->value('quantity'));
    }

    /** A material is not a recipe: there is nothing to build fibre out of. */
    public function test_an_item_with_no_recipe_is_rejected(): void
    {
        $this->actingAs($this->richUser())
            ->post(route('companion.build.store'), ['item' => 'fibre'])
            ->assertSessionHasErrors('item');
    }
}
