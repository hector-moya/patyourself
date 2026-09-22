<?php

namespace Tests\Feature\Companion;

use App\Http\Controllers\CompanionController;
use App\Http\Controllers\CompanionItemController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one route the chest adds.
 *
 * Posted from inside the bag, so the bag stays up: the point of the press is to
 * watch the row move from one list to the other.
 *
 * Only carried categories are routable at all. A tool or a container reaching
 * this is a malformed request rather than a state of the world, so it is a 404
 * rather than a line in Blob's voice — the same rule
 * {@see CompanionItemController} follows.
 */
class CompanionStashScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_putting_something_down_says_what_blob_did(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'planks', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'in',
                'amount' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'Blob puts 2 planks in the chest.');

        $this->assertSame(2, (int) $companion->stashItems()->where('item', 'planks')->value('quantity'));
    }

    public function test_fetching_something_back_says_what_blob_did(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->stashItems()->create(['item' => 'fibre', 'quantity' => 4]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'fibre',
                'direction' => 'out',
                'amount' => 4,
            ])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'Blob lifts 4 fibre back out of the chest.');
    }

    /** A full bag says where the thing stays, because nothing is destroyed. */
    public function test_a_full_bag_says_the_planks_stay_in_the_chest(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $companion->stashItems()->create(['item' => 'planks', 'quantity' => 4]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 5]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'out',
                'amount' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas(CompanionController::SAID_KEY, 'There is nowhere to put them. The planks stay in the chest.');
    }

    /** A press that moved nothing says nothing rather than narrating itself. */
    public function test_moving_nothing_says_nothing(): void
    {
        $user = User::factory()->create();
        $user->companion()->firstOrCreate([]);

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'in',
            ])
            ->assertRedirect()
            ->assertSessionMissing(CompanionController::SAID_KEY);
    }

    /** A tool is never offered here, so a request naming one is malformed. */
    public function test_a_tool_is_a_404_rather_than_a_sentence(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'axe',
                'direction' => 'in',
            ])
            ->assertNotFound();
    }

    public function test_an_unknown_direction_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('companion.stash.store'), [
                'item' => 'planks',
                'direction' => 'sideways',
            ])
            ->assertSessionHasErrors('direction');
    }
}
