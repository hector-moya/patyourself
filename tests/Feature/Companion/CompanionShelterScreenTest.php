<?php

namespace Tests\Feature\Companion;

use App\Http\Controllers\CompanionController;
use App\Models\Companion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write routes this phase adds, driven the way the screen drives them.
 *
 * Every refusal here is an ordinary state of the world rather than an error:
 * it comes back as a line in Blob's own voice with the bag still open, because
 * these are posted from inside the bag and the point of the press is to watch
 * something change.
 */
class CompanionShelterScreenTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Companion} */
    private function clearing(): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 6]);

        return [$user, $companion];
    }

    /**
     * Taking a chosen amount is the same route as clicking the node, because
     * from the user's side it is the same gesture with an amount attached.
     */
    public function test_taking_a_chosen_amount_leaves_the_rest_standing(): void
    {
        [$user, $companion] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']), ['take' => 2])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'Blob comes back with 2 fibre.');

        $this->assertSame(2, (int) $companion->items()->where('item', 'fibre')->value('quantity'));
        $this->assertSame(4, (int) $companion->nodes()->where('node', 'reeds')->value('available'));
    }

    /** A take comes from inside the bag, so the bag stays up to show the result. */
    public function test_taking_a_chosen_amount_leaves_the_bag_open(): void
    {
        [$user] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']), ['take' => 1])
            ->assertSessionHas(CompanionController::STAY_KEY, true);
    }

    /** A plain click in the clearing does not, and still fills the bag. */
    public function test_a_plain_click_still_fills_the_bag_and_opens_nothing(): void
    {
        [$user, $companion] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']))
            ->assertSessionMissing(CompanionController::STAY_KEY);

        $this->assertSame(5, (int) $companion->items()->where('item', 'fibre')->value('quantity'));
    }

    /** Nought and less are not amounts. The route says so before Blob moves. */
    public function test_asking_for_none_is_rejected_by_the_route(): void
    {
        [$user] = $this->clearing();

        $this->actingAs($user)
            ->post(route('companion.nodes.store', ['node' => 'reeds']), ['take' => 0])
            ->assertSessionHasErrors('take');
    }

    /** Dropping says what Blob did, names the thing, and leaves the bag open. */
    public function test_dropping_says_what_blob_did(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'timber', 'quantity' => 3]);

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'Blob tips out the timber. The bag is lighter.')
            ->assertSessionHas(CompanionController::STAY_KEY, true);

        $this->assertSame(0, $companion->items()->where('item', 'timber')->count());
    }

    /** A renamed companion is named in it, because every line carries {name}. */
    public function test_the_drop_line_carries_the_companions_name(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->update(['name' => 'Pebble']);
        $companion->items()->create(['item' => 'timber', 'quantity' => 1]);

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertSessionHas(CompanionController::SAID_KEY, 'Pebble tips out the timber. The bag is lighter.');
    }

    /** A tool has no drop route at all: there is nothing there to reach. */
    public function test_a_tool_has_no_drop_route(): void
    {
        [$user, $companion] = $this->clearing();
        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'axe']))
            ->assertNotFound();

        $this->assertSame(1, (int) $companion->items()->where('item', 'axe')->value('quantity'));
    }

    /**
     * Nothing was there. Silence is the correct response here too: a line
     * about a stack that was never held would be the app narrating a press
     * that did nothing, which is exactly what the controller's own comment
     * says this branch exists to avoid.
     */
    public function test_dropping_a_stack_not_held_says_nothing(): void
    {
        [$user] = $this->clearing();

        $this->actingAs($user)
            ->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertRedirect()
            ->assertSessionMissing(CompanionController::SAID_KEY)
            ->assertSessionHas(CompanionController::STAY_KEY, true);
    }

    public function test_dropping_requires_signing_in(): void
    {
        $this->delete(route('companion.items.destroy', ['item' => 'timber']))
            ->assertRedirect(route('login'));
    }
}
