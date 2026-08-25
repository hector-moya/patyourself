<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_dashboard_renders_the_loop_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('loops/index'));
    }

    public function test_the_chat_endpoint_is_gone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/chat', ['message' => 'hello'])
            ->assertNotFound();
    }

    public function test_loops_live_at_the_loops_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/loops')->assertOk();
        $this->actingAs($user)->get('/intentions')->assertNotFound();
        $this->assertSame('/loops', route('loops.index', absolute: false));
    }
}
