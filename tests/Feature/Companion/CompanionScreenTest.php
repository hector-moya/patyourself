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
                ->where('companion.stage_index', 3)
                ->where('companion.features', ['blob', 'legs'])
                ->where('companion.items', [['type' => 'shoes', 'variant' => null]])
                ->has('companion.unlocks', 3)
                // The room starts empty, and an empty room is empty — not a
                // set of outlines waiting to be filled in.
                ->where('companion.room_objects', [])
                ->where('companion.renderer', 'svg')
                ->has('companion.room.day')
                ->has('companion.room.night'),
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
}
