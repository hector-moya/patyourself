<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renaming: the one thing about Blob the user simply states.
 *
 * It costs nothing, it is not on the ladder and it is not on the skill list.
 * Naming a creature is not an achievement, so nothing about this is earned and
 * nothing about it is announced.
 */
class CompanionRenameTest extends TestCase
{
    use RefreshDatabase;

    private function userWithBlob(): User
    {
        $user = User::factory()->create();

        ActionLog::factory()->create([
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'logged_at' => now()->subDay(),
        ]);

        return $user;
    }

    public function test_a_rename_persists_and_reaches_the_screen(): void
    {
        $user = $this->userWithBlob();

        $this->actingAs($user)
            ->patch(route('companion.name'), ['name' => 'Pebble'])
            ->assertRedirect();

        $this->assertSame('Pebble', $user->companion()->value('name'));

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn ($page) => $page->where('companion.name', 'Pebble'));
    }

    /** No companion row yet is not an error: naming one creates it. */
    public function test_renaming_creates_the_companion_if_there_is_none(): void
    {
        $user = $this->userWithBlob();

        $this->assertDatabaseCount('companions', 0);

        $this->actingAs($user)->patch(route('companion.name'), ['name' => 'Pebble']);

        $this->assertDatabaseCount('companions', 1);
    }

    /** Clearing it puts the name back rather than leaving a nameless companion. */
    public function test_clearing_the_name_goes_back_to_blob(): void
    {
        $user = $this->userWithBlob();
        $user->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->actingAs($user)->patch(route('companion.name'), ['name' => '']);

        $this->assertNull($user->companion()->value('name'));

        $this->actingAs($user)
            ->get(route('companion'))
            ->assertInertia(fn ($page) => $page->where('companion.name', 'Blob'));
    }

    /** A name of nothing but spaces is stored as absent, not as a gap. */
    public function test_a_whitespace_name_is_stored_as_absent(): void
    {
        $user = $this->userWithBlob();

        $this->actingAs($user)->patch(route('companion.name'), ['name' => '   ']);

        $this->assertNull($user->companion()->value('name'));
    }

    /** Surrounding space is trimmed rather than stored. */
    public function test_a_name_is_trimmed(): void
    {
        $user = $this->userWithBlob();

        $this->actingAs($user)->patch(route('companion.name'), ['name' => '  Pebble  ']);

        $this->assertSame('Pebble', $user->companion()->value('name'));
    }

    public function test_an_over_long_name_is_rejected(): void
    {
        $user = $this->userWithBlob();

        $this->actingAs($user)
            ->patch(route('companion.name'), ['name' => str_repeat('a', 25)])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('companions', 0);
    }

    public function test_renaming_requires_signing_in(): void
    {
        $this->patch(route('companion.name'), ['name' => 'Pebble'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('companions', 0);
    }

    /** One person's rename never touches another's companion. */
    public function test_a_rename_only_touches_your_own_companion(): void
    {
        $user = $this->userWithBlob();
        $stranger = $this->userWithBlob();
        $stranger->companion()->firstOrCreate([])->update(['name' => 'Pebble']);

        $this->actingAs($user)->patch(route('companion.name'), ['name' => 'Moss']);

        $this->assertSame('Moss', $user->companion()->value('name'));
        $this->assertSame('Pebble', $stranger->companion()->value('name'));
    }

    /** Renaming is not progress: nothing about the ladder moves. */
    public function test_renaming_earns_nothing(): void
    {
        $user = $this->userWithBlob();

        $before = $this->actingAs($user)->get(route('companion'));
        $stage = $before->viewData('page')['props']['companion']['stage_index'];

        $this->actingAs($user)->patch(route('companion.name'), ['name' => 'Pebble']);

        $after = $this->actingAs($user)->get(route('companion'));

        $this->assertSame($stage, $after->viewData('page')['props']['companion']['stage_index']);
        $this->assertSame(0, (int) $user->companion()->value('xp_spent'));
    }
}
