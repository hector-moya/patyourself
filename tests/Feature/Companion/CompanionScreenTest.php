<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Blob's own screen, and its corner instance on Today.
 *
 * Both are reads. Neither carries anything the user has not already done: no
 * locked slot, no remaining count, no preview of the next stage.
 */
class CompanionScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        CarbonImmutable::setTestNow('2026-08-27 12:00:00');
    }

    private function logOutcomes(User $user, int $count): void
    {
        for ($index = 0; $index < $count; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'logged_at' => now()->subDays(20 - $index),
            ]);
        }
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/companion')->assertRedirect('/login');
    }

    public function test_it_renders_blob_from_the_record(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 5);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('companion')
                ->where('companion.stage_index', 4)
                ->where('companion.features', ['blob', 'legs', 'arms'])
                ->where('companion.items', [['type' => 'shoes', 'variant' => null]])
                ->has('companion.unlocks', 4)
                // The room starts empty, and an empty room is empty — not a
                // set of outlines waiting to be filled in.
                ->where('companion.room_objects', [])
                ->where('companion.renderer', 'sprite')
                ->has('companion.room.day')
                ->has('companion.room.night')
                // The flag the drawing keys off, asserted at the payload's
                // edge. `toArray()` passes the room through whole today, so
                // this looks redundant — it is not: a payload silently
                // dropping a newly added key has bitten this project three
                // times in one branch.
                ->where('companion.room.night.asleep', true)
            );
    }

    /**
     * The screen states what has happened and stops there. Anything describing
     * what has not — a locked slot, a remaining count, the next stage — would
     * make it a checklist.
     */
    public function test_the_payload_never_names_what_has_not_happened(): void
    {
        $user = User::factory()->create();
        $this->logOutcomes($user, 1);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('companion.unlocks', 1)
                ->missing('companion.next')
                ->missing('companion.locked')
                ->missing('companion.remaining')
                ->missing('companion.progress'),
            );
    }

    public function test_a_user_with_no_record_gets_an_empty_blob_rather_than_an_error(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companion.stage_index', 0)
                ->where('companion.unlocks', [])
                ->where('companion.latest_unlock', null),
            );
    }

    public function test_today_carries_blob_for_its_corner(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->logOutcomes($user, 3);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->where('companion.stage_index', 2)
                ->where('companion.features', ['blob', 'legs']),
            );
    }

    /**
     * The bag rides along with the room and the record. Assembled by
     * CompanionBag rather than here, so this only checks that it arrives and
     * that the balance agrees with the wallet.
     */
    public function test_the_screen_carries_the_bag(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->logOutcomes($user, 5);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bag')
                // Five outcomes on five separate days: 3 each.
                ->where('bag.xp', 15)
                ->where('bag.capacity', 5)
                ->where('bag.held', 0)
                ->where('bag.name', 'Blob')
                ->where('bag.items', [])
                ->where('bag.skills', []),
            );
    }

    /**
     * Looking at the screen writes nothing. The companion row appears on the
     * first CHOICE, not on the first visit — a row created by reading would
     * mean every account that ever opened this page has one.
     */
    public function test_looking_at_the_screen_creates_no_companion(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->logOutcomes($user, 5);

        $this->actingAs($user)->get('/companion')->assertOk();

        $this->assertDatabaseCount('companions', 0);
    }

    /** The bag's own numbers follow what has actually been gathered. */
    public function test_the_bag_reports_what_is_held_against_capacity(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->logOutcomes($user, 5);

        $companion = $user->companion()->firstOrCreate([]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);
        $companion->items()->create(['item' => 'basket', 'quantity' => 1]);

        $this->actingAs($user)
            ->get('/companion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('bag.held', 3)
                // Hands plus one basket, and the basket does not occupy the
                // room it creates.
                ->where('bag.capacity', 10),
            );
    }
}
