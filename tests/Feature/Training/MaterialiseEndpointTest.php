<?php

namespace Tests\Feature\Training;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\PerformedSet;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP seam that begins a recording session.
 *
 * A {@see PerformedSet} keys to an {@see Occurrence}, and sets are
 * ticked off *during* a session, long before anyone presses Done or Missed. A
 * cue-anchored action ("train after work") has no occurrence until something
 * creates one, so beginning to record must materialise the occasion, over
 * HTTP, from the screen.
 *
 * The rule this whole file exists to pin: materialising must not create an
 * {@see ActionLog}. `logCount`, read through {@see CompanionResolver}, is what
 * the companion ladder spends — a module that could mint fuel by being more
 * granular than "one occasion" would break the economy the whole plug-in
 * architecture rests on.
 */
class MaterialiseEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * A cue-anchored action. `anchored()` is what pins both of ActionFactory's
     * random fields — `recurrence` and `series_started_at` — to null, which is
     * what makes the action produce no grid of occasions at all.
     */
    private function anchoredAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->anchored()
            ->create();
    }

    /**
     * A clock-scheduled action, with the factory's two random fields pinned
     * explicitly so the grid it stands for is the same on every run.
     */
    private function scheduledAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => now()->subDays(5)->setTime(19, 0),
                'status' => Action::STATUS_ACTIVE,
            ]);
    }

    public function test_it_materialises_an_occasion_and_returns_it(): void
    {
        $user = $this->user();
        $action = $this->anchoredAction($user);

        $this->assertSame(0, Occurrence::count());

        $response = $this->actingAs($user)->post("/actions/{$action->id}/session");

        $occurrence = Occurrence::sole();

        $response->assertRedirect();
        $response->assertSessionHas('occurrence_id', $occurrence->id);
        $this->assertSame($action->id, $occurrence->action_id);
    }

    public function test_it_creates_no_log_and_moves_the_count_by_zero(): void
    {
        $user = $this->user();
        $action = $this->anchoredAction($user);

        $this->actingAs($user)->post("/actions/{$action->id}/session");

        // The occasion now exists to hang sets on. The verdict is still a
        // separate press, by a person, afterwards.
        $this->assertSame(0, ActionLog::count());

        // The same read through Blob's own resolver, which is the number the
        // ladder spends. The table count alone would pin the row; this pins
        // the economy.
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_two_posts_in_the_same_second_return_the_same_occasion(): void
    {
        $user = $this->user();
        $action = $this->anchoredAction($user);

        $first = $this->actingAs($user)->post("/actions/{$action->id}/session");
        $second = $this->actingAs($user)->post("/actions/{$action->id}/session");

        // Two taps on Start inside one second. Occasions are stored to the
        // second and unique on (action_id, scheduled_for), so a path that
        // minted rather than resolved would either split one session in two
        // or collide on the index.
        $occurrence = Occurrence::sole();

        $first->assertSessionHas('occurrence_id', $occurrence->id);
        $second->assertSessionHas('occurrence_id', $occurrence->id);
    }

    public function test_it_returns_the_existing_slot_for_a_scheduled_action_before_its_time(): void
    {
        $this->travelTo('2026-08-26 18:00:00');

        $user = $this->user();
        $action = $this->scheduledAction($user);
        $tonight = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->setTime(19, 0),
        ]);

        $response = $this->actingAs($user)->post("/actions/{$action->id}/session");

        // Warming up at 18:00 for a session the grid puts at 19:00 must attach
        // to that slot rather than mint a phantom beside it — every other case
        // in this file uses a cue-anchored action, which has no slot ahead of
        // the clock to get this wrong about.
        $response->assertSessionHas('occurrence_id', $tonight->id);
        $this->assertSame(1, Occurrence::count());
    }

    public function test_another_users_action_is_refused(): void
    {
        $owner = $this->user();
        $action = $this->anchoredAction($owner);
        $intruder = $this->user();

        $this->actingAs($intruder)
            ->post("/actions/{$action->id}/session")
            ->assertForbidden();

        $this->assertSame(0, Occurrence::count());
    }
}
