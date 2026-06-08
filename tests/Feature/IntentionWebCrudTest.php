<?php

namespace Tests\Feature;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Inertia web side of loop CRUD — the write endpoints the list/detail
 * screens (Tasks 19–20) post to. Reads are served by those screens and by the
 * JSON API; here we verify the writes go through the same shared Actions and
 * respect ownership.
 */
class IntentionWebCrudTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Morning pages',
            'description' => 'Write three pages by hand after coffee.',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Coffee finishes brewing',
            'craving' => 'A clear head before the day starts',
            'response' => 'Write three longhand pages',
            'reward' => 'Feeling unblocked and calm',
        ], $overrides);
    }

    public function test_guests_cannot_create_loops(): void
    {
        $this->post('/intentions', $this->payload())->assertRedirect('/login');
    }

    public function test_store_creates_a_loop_and_redirects(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/intentions', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('intentions', [
            'user_id' => $user->id,
            'title' => 'Morning pages',
        ]);
    }

    public function test_store_validates_input(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/dashboard')
            ->post('/intentions', $this->payload(['title' => '']))
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors('title');
    }

    public function test_update_changes_the_loop_and_redirects(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create(['title' => 'Old']);

        $this->actingAs($user)
            ->patch("/intentions/{$intention->id}", ['title' => 'New'])
            ->assertRedirect();

        $this->assertSame('New', $intention->fresh()->title);
    }

    public function test_update_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create(['title' => 'Old']);

        $this->actingAs(User::factory()->create())
            ->patch("/intentions/{$intention->id}", ['title' => 'New'])
            ->assertForbidden();

        $this->assertSame('Old', $intention->fresh()->title);
    }

    public function test_destroy_deletes_the_loop_and_redirects(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->delete("/intentions/{$intention->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('intentions', ['id' => $intention->id]);
    }

    public function test_destroy_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete("/intentions/{$intention->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('intentions', ['id' => $intention->id]);
    }
}
