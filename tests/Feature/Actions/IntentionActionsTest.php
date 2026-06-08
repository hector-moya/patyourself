<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateIntention;
use App\Actions\DeleteIntention;
use App\Actions\UpdateIntention;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared write path for manual loop CRUD. Both the Inertia web controller
 * and the JSON API call into these Actions — they are the only place #13 writes
 * intentions to the database.
 */
class IntentionActionsTest extends TestCase
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

    public function test_create_persists_a_loop_owned_by_the_user(): void
    {
        $user = User::factory()->create();

        $intention = app(CreateIntention::class)->handle($user, $this->payload());

        $this->assertTrue($intention->exists);
        $this->assertSame($user->id, $intention->user_id);
        $this->assertSame('Morning pages', $intention->title);
        $this->assertSame(Intention::TYPE_BUILD, $intention->type);
        $this->assertDatabaseHas('intentions', [
            'id' => $intention->id,
            'user_id' => $user->id,
            'title' => 'Morning pages',
        ]);
    }

    public function test_create_defaults_status_to_active(): void
    {
        $user = User::factory()->create();

        $intention = app(CreateIntention::class)->handle($user, $this->payload());

        $this->assertSame(Intention::STATUS_ACTIVE, $intention->status);
    }

    public function test_create_records_manual_authorship_in_metadata(): void
    {
        $user = User::factory()->create();

        $intention = app(CreateIntention::class)->handle($user, $this->payload());

        $this->assertSame('user', $intention->metadata['authored_by']);
    }

    public function test_update_changes_only_provided_fields(): void
    {
        $user = User::factory()->create();
        $intention = Intention::factory()->for($user)->create([
            'title' => 'Old title',
            'cue' => 'Old cue',
        ]);

        $updated = app(UpdateIntention::class)->handle($intention, [
            'title' => 'New title',
        ]);

        $this->assertSame('New title', $updated->title);
        $this->assertSame('Old cue', $updated->fresh()->cue);
    }

    public function test_update_can_change_status(): void
    {
        $intention = Intention::factory()->create(['status' => Intention::STATUS_ACTIVE]);

        $updated = app(UpdateIntention::class)->handle($intention, [
            'status' => Intention::STATUS_PAUSED,
        ]);

        $this->assertSame(Intention::STATUS_PAUSED, $updated->status);
    }

    public function test_delete_removes_the_loop(): void
    {
        $intention = Intention::factory()->create();

        app(DeleteIntention::class)->handle($intention);

        $this->assertDatabaseMissing('intentions', ['id' => $intention->id]);
    }
}
