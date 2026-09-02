<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The screen that hands the record over.
 *
 * `/export` worked from the day it was written and nothing in the app linked
 * to it — a door with no handle. This screen is the handle, and it is only
 * that: two links and a sentence saying what comes down. There is no importer
 * and nothing here suggests one.
 */
class RecordScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return list<string>
     */
    private function middlewareFor(string $name): array
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertNotNull($route, "Route [{$name}] is not registered.");

        return $route->gatherMiddleware();
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/settings/record')->assertRedirect('/login');
    }

    public function test_it_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings/record')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('settings/record'));
    }

    /**
     * The page and the endpoint it links to have to sit behind the same gate.
     * A page whose only two links answer 403 is worse than no page, and the two
     * routes are declared in different files, so nothing else would notice them
     * drifting apart.
     */
    public function test_it_sits_behind_the_same_gate_as_the_endpoint_it_links_to(): void
    {
        $this->assertSame(
            $this->middlewareFor('export.show'),
            $this->middlewareFor('record.edit'),
        );
    }
}
