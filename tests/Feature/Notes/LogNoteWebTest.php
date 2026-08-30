<?php

namespace Tests\Feature\Notes;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogNoteWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_writes_a_note_on_their_loop(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.notes.store', $loop), ['body' => 'Skipped because the kitchen was closed.'])
            ->assertRedirect();

        $this->assertDatabaseHas('notes', [
            'intention_id' => $loop->id,
            'body' => 'Skipped because the kitchen was closed.',
        ]);
    }

    public function test_the_body_is_stored_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $body = '  two spaces before.  And after.  ';

        $this->actingAs($user)->post(route('loops.notes.store', $loop), ['body' => $body]);

        $this->assertSame($body, $loop->notes()->latest('id')->first()->body);
    }

    public function test_a_blank_note_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.notes.store', $loop), ['body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, $loop->notes()->count());
    }

    public function test_a_stranger_cannot_write_a_note(): void
    {
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for(User::factory())->create();

        $this->actingAs($stranger)
            ->post(route('loops.notes.store', $loop), ['body' => 'Not mine.'])
            ->assertForbidden();
    }
}
