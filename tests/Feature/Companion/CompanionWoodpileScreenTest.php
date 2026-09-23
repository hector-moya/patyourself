<?php

namespace Tests\Feature\Companion;

use App\Http\Controllers\CompanionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompanionWoodpileScreenTest extends TestCase
{
    use RefreshDatabase;

    private function userWithAShelter(): User
    {
        $user = User::factory()->create();
        $user->companion()->create(['shelter' => 'lean-to']);

        return $user;
    }

    public function test_stacking_wood_raises_the_pile_and_says_so(): void
    {
        $user = $this->userWithAShelter();
        $user->companion->items()->create(['item' => 'deadfall', 'quantity' => 3]);

        $response = $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'deadfall']);

        $response->assertRedirect();
        $response->assertSessionHas(CompanionController::SAID_KEY);

        $this->assertSame(3, $user->companion->fresh()->woodpile);
        $this->assertStringContainsString(
            'against the wall',
            (string) session(CompanionController::SAID_KEY),
        );
    }

    public function test_what_the_pile_does_not_take_is_not_a_route(): void
    {
        $user = $this->userWithAShelter();
        $user->companion->items()->create(['item' => 'fibre', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'fibre'])
            ->assertNotFound();
    }

    public function test_there_is_no_pile_without_a_shelter_to_stand_it_in(): void
    {
        $user = User::factory()->create();
        $user->companion()->create([]);
        $user->companion->items()->create(['item' => 'deadfall', 'quantity' => 3]);

        $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'deadfall'])
            ->assertNotFound();

        $this->assertSame(0, $user->companion->fresh()->woodpile);
    }

    public function test_a_press_that_moved_nothing_says_nothing(): void
    {
        $user = $this->userWithAShelter();

        $this->actingAs($user)
            ->post(route('companion.woodpile.store'), ['item' => 'deadfall'])
            ->assertRedirect()
            ->assertSessionMissing(CompanionController::SAID_KEY);
    }

    /**
     * NOTHING COMES BACK OUT. The guard is the absence of a route: a later
     * phase adding an "unstack" would make the pile a third place to keep
     * things, and §16 of the spec names that as the first thing that would make
     * this design wrong.
     */
    public function test_no_route_lowers_the_pile(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(static fn (string $name): bool => str_starts_with($name, 'companion.woodpile.'))
            ->values()
            ->all();

        $this->assertSame(['companion.woodpile.store'], $names);
    }
}
