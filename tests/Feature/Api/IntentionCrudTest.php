<?php

namespace Tests\Feature\Api;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * JSON CRUD for intentions/loops over the token-authenticated API. Shares its
 * write path (Actions) and JSON shape (IntentionResource) with the web side.
 */
class IntentionCrudTest extends TestCase
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

    public function test_guests_are_unauthorized(): void
    {
        $this->getJson('/api/intentions')->assertUnauthorized();
    }

    public function test_index_lists_only_the_users_own_loops(): void
    {
        $user = User::factory()->create();
        Intention::factory()->count(2)->for($user)->create();
        Intention::factory()->count(3)->create(); // someone else's

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/intentions');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'title', 'type', 'status', 'cue', 'craving', 'response', 'reward']]]);
    }

    public function test_index_can_filter_by_status(): void
    {
        $user = User::factory()->create();
        Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        Intention::factory()->for($user)->create(['status' => Intention::STATUS_ARCHIVED]);

        Sanctum::actingAs($user);

        $this->getJson('/api/intentions?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Intention::STATUS_ACTIVE);
    }

    public function test_store_creates_a_loop(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/intentions', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Morning pages')
            ->assertJsonPath('data.type', Intention::TYPE_BUILD)
            ->assertJsonPath('data.status', Intention::STATUS_ACTIVE);

        $this->assertDatabaseHas('intentions', [
            'user_id' => $user->id,
            'title' => 'Morning pages',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/intentions', $this->payload(['title' => '', 'type' => 'nonsense']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'type']);
    }

    public function test_show_returns_a_loop_with_its_active_strategy(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();
        $intention->strategies()->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay the book on the pillow',
            'rationale' => 'Make the cue impossible to miss',
            'change_reason' => Strategy::REASON_INITIAL,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/intentions/{$intention->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $intention->id)
            ->assertJsonPath('data.strategy.intervention_point', Strategy::POINT_CUE);
    }

    public function test_show_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/intentions/{$intention->id}")->assertForbidden();
    }

    public function test_update_changes_fields(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create(['title' => 'Old']);

        Sanctum::actingAs($user);

        $this->patchJson("/api/intentions/{$intention->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New');

        $this->assertSame('New', $intention->fresh()->title);
    }

    public function test_update_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create(['title' => 'Old']);

        Sanctum::actingAs(User::factory()->create());

        $this->patchJson("/api/intentions/{$intention->id}", ['title' => 'New'])->assertForbidden();
        $this->assertSame('Old', $intention->fresh()->title);
    }

    public function test_destroy_deletes_the_loop(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->deleteJson("/api/intentions/{$intention->id}")->assertNoContent();
        $this->assertDatabaseMissing('intentions', ['id' => $intention->id]);
    }

    public function test_destroy_forbids_another_users_loop(): void
    {
        $intention = Intention::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson("/api/intentions/{$intention->id}")->assertForbidden();
        $this->assertDatabaseHas('intentions', ['id' => $intention->id]);
    }
}
