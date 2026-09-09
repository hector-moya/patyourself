<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The write surfaces that had no throttle, and the one unbounded read.
 *
 * `configureRateLimiting()` went when the in-app coach did, and the MCP
 * endpoint became the app's primary write surface in the same period — ten of
 * its tools write. Nothing here is urgent for a single user; what makes it
 * worth pinning is that the absence was an accident of a refactor rather than
 * a decision, so nothing would have noticed it coming back.
 *
 * Two of these are asserted behaviourally, by exhausting the bucket and
 * watching the next request turn 429. `POST /mcp` is asserted structurally
 * instead: proving it takes 121 authenticated JSON-RPC calls to move the
 * number would cost far more than the assertion is worth, and the limit there
 * is a configuration fact rather than a computed one.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The one that matters most, and the one the ticket did not name.
     *
     * `POST oauth/register` is dynamic client registration: it mints an OAuth
     * client row for anyone who asks, unauthenticated, which is what lets
     * claude.ai register itself without a client id being issued by hand. It
     * is the only unauthenticated writing surface in the app.
     *
     * Killing mutation: drop the `throttle:30,1` group wrapping
     * `Mcp::oauthRoutes()` in `routes/ai.php`. Every request then returns its
     * ordinary status and the 429 never arrives.
     */
    public function test_dynamic_client_registration_is_throttled_for_anonymous_callers(): void
    {
        $tooMany = 31;
        $statuses = [];

        for ($attempt = 0; $attempt < $tooMany; $attempt++) {
            $statuses[] = $this->postJson('/oauth/register', [])->status();
        }

        // The last one is refused, and it is refused for being too many rather
        // than for being malformed — the thirtieth got whatever the endpoint
        // makes of an empty body, and only the thirty-first is a 429.
        $this->assertSame(429, end($statuses));
        $this->assertNotContains(429, array_slice($statuses, 0, 30));
    }

    /**
     * The catalogue search runs a `like` scan over 876 imported rows plus the
     * user's own. Every other training route resolves a single bound model;
     * this is the module's only unbounded-cardinality read, which is why it is
     * the only one carrying a limit.
     *
     * Killing mutation: remove `->middleware('throttle:60,1')` from the
     * `training.exercises.index` route. The 61st request returns 200 and the
     * first assertion fails.
     */
    public function test_the_catalogue_search_is_throttled_per_user(): void
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->actingAs($user)->getJson(route('training.exercises.index'))
                ->assertOk();
        }

        $this->actingAs($user)->getJson(route('training.exercises.index'))
            ->assertStatus(429);

        // Keyed per user, not per IP: a second user arrives with a full budget
        // even though the first has spent theirs from the same address. Without
        // this, the test would still pass against a per-IP limiter, which is a
        // different and worse thing to have built.
        $this->actingAs(User::factory()->create())
            ->getJson(route('training.exercises.index'))
            ->assertOk();
    }

    /**
     * Asserted on the route definition rather than by exhausting it. The limit
     * is generous on purpose — a conversation can call several tools in a
     * burst and then sit idle, so what 120/minute bounds is a loop, not a
     * session.
     *
     * Killing mutation: remove `'throttle:120,1'` from the `Mcp::web()`
     * middleware array in `routes/ai.php`. The collected middleware no longer
     * contains a `ThrottleRequests` entry and this fails.
     */
    public function test_the_mcp_endpoint_carries_a_throttle(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($candidate): bool => $candidate->uri() === 'mcp'
                && in_array('POST', $candidate->methods(), true));

        $this->assertNotNull($route, 'The MCP endpoint is not registered.');

        // Asserted as the alias string the route was declared with. The
        // resolved `ThrottleRequests` class name is what `route:list` prints,
        // but `gatherMiddleware()` hands back what was written, unresolved.
        $this->assertContains(
            'throttle:120,1',
            $route->gatherMiddleware(),
            'POST /mcp is the app\'s primary write surface and must carry a throttle.',
        );

        // The throttle is additional to the token checks, not a replacement for
        // them — asserted here so a future edit cannot satisfy this test by
        // swapping one for the other.
        $this->assertContains('auth:api', $route->gatherMiddleware());
    }
}
