<?php

namespace Tests\Feature\Progress;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-loop progress screen folded into the lab record at `/loops/{loop}`,
 * which now carries the current experiment, the per-version evidence and the
 * reflection on one page. This route survives as a redirect so nothing that
 * generates the URL breaks and no bookmark 404s.
 *
 * The content assertions this file used to make moved to IntentionScreensTest
 * against `/loops/{loop}` — the metrics by value, the WriteReflection
 * round-trip, and both experiment-field serializations. They were ported, not
 * dropped. What remains here is the redirect's own contract, including the one
 * behaviour a bare redirect would quietly lose: a stranger still gets 403 at the
 * door rather than a 302 into someone else's loop.
 */
class ProgressShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_owner_is_redirected_into_the_lab_record(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertRedirect("/loops/{$loop->id}");
    }

    /**
     * A loop that is no longer active still has a record worth reading, so it
     * redirects rather than 404ing.
     */
    public function test_a_non_active_owned_loop_redirects_too(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->completed()->create();

        $this->actingAs($user)
            ->get("/progress/{$loop->id}")
            ->assertRedirect("/loops/{$loop->id}");
    }

    /**
     * Authorization happens on this route, not only on its target. A redirect
     * that resolved the model without a gate would answer 302 for a stranger and
     * leak that the loop exists.
     */
    public function test_forbids_viewing_another_users_loop(): void
    {
        $owner = User::factory()->create();
        $loop = Intention::factory()->for($owner)->create(['status' => Intention::STATUS_ACTIVE]);

        $this->actingAs(User::factory()->create())
            ->get("/progress/{$loop->id}")
            ->assertForbidden();
    }

    public function test_guests_are_redirected(): void
    {
        $loop = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);

        $this->get("/progress/{$loop->id}")->assertRedirect('/login');
    }
}
