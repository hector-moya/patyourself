<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

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

    /**
     * The dashboard used to alias the loops index — `dashboard` and
     * `loops.index` pointed at the same controller while the notebook screen
     * was unbuilt. They now mean two different things: today's occasions here,
     * the loop list at /loops.
     *
     * The route *name* still has to resolve, because config/fortify.php sets
     * `home` to /dashboard and every login lands on it.
     */
    public function test_dashboard_renders_the_notebook_not_the_loop_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('dashboard'));

        $this->assertSame('/dashboard', route('dashboard', absolute: false));
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
