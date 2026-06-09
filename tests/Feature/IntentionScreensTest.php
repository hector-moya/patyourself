<?php

namespace Tests\Feature;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The three-screen routing: chat home (coach), loops list, and loop detail —
 * each an Inertia page rendered in the shared CoachLayout shell. Verifies the
 * controllers hand each screen the right component + props and gate detail on
 * ownership.
 */
class IntentionScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Screen content ships in Tasks 18–20; the page render only needs
        // Inertia's component + props, not a built Vite manifest.
        $this->withoutVite();
    }

    public function test_chat_home_renders_the_coach_screen_with_active_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->for($user)->create(['status' => Intention::STATUS_ARCHIVED]);
        Intention::factory()->create(); // another user's

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('coach')
                ->has('intentions', 2)
            );
    }

    public function test_guests_are_redirected_from_the_loops_list(): void
    {
        $this->get('/intentions')->assertRedirect('/login');
    }

    public function test_loops_list_renders_only_the_users_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create();
        Intention::factory()->create();

        $this->actingAs($user)
            ->get('/intentions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('intentions/index')
                ->has('intentions', 2)
            );
    }

    public function test_loops_list_surfaces_active_loops_first(): void
    {
        $user = User::factory()->create();
        $archived = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ARCHIVED]);
        $active = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get('/intentions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('intentions/index')
                ->where('intentions.0.id', $active->id)
                ->where('intentions.1.id', $archived->id)
            );
    }

    public function test_loop_detail_renders_the_loop_and_its_strategy_history(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $intention->strategies()->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay the book on the pillow',
            'rationale' => 'Make the cue impossible to miss',
            'change_reason' => Strategy::REASON_INITIAL,
        ]);

        $this->actingAs($user)
            ->get("/intentions/{$intention->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('intentions/show')
                ->where('intention.id', $intention->id)
                ->has('strategies', 1)
                ->where('strategies.0.version', 1)
            );
    }

    public function test_loop_detail_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get("/intentions/{$intention->id}")
            ->assertForbidden();
    }
}
