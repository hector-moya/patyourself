<?php

namespace Tests\Feature\Actions;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionLayerWebTest extends TestCase
{
    use RefreshDatabase;

    private function loopWithActiveVersion(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop, 'intention')->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
        ]);

        return $loop->refresh();
    }

    public function test_the_owner_adds_a_clock_action(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.actions.store', $loop), [
                'title' => 'Second meal check-in',
                'kind' => 'clock',
                'time' => '19:00',
                'recurrence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('actions', [
            'intention_id' => $loop->id,
            'title' => 'Second meal check-in',
            'recurrence' => 'daily',
        ]);
    }

    public function test_the_owner_adds_an_anchored_action(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)->post(route('loops.actions.store', $loop), [
            'title' => 'Pause before the second helping',
            'kind' => 'anchored',
            'anchor' => 'after serving dinner',
        ])->assertRedirect();

        // `schedule_kind` and `anchor` live in the `metadata` JSON column, not
        // as top-level columns (see Action::casts() and CreateAction::handle()),
        // so they are asserted against the fetched model rather than
        // assertDatabaseHas.
        $action = $loop->actions()->latest('id')->first();
        $this->assertNotNull($action);
        $this->assertSame('Pause before the second helping', $action->title);
        $this->assertSame('anchored', $action->metadata['schedule_kind']);
        $this->assertSame('after serving dinner', $action->metadata['anchor']);
    }

    public function test_a_clock_action_without_a_time_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $this->actingAs($user)
            ->post(route('loops.actions.store', $loop), ['title' => 'No time', 'kind' => 'clock'])
            ->assertSessionHasErrors('time');
    }

    public function test_adding_an_action_to_a_loop_with_no_active_version_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('loops.actions.store', $loop), [
                'title' => 'Orphan',
                'kind' => 'anchored',
                'anchor' => 'whenever',
            ])
            ->assertSessionHasErrors('title');
    }

    public function test_a_stranger_cannot_add_an_action(): void
    {
        $stranger = User::factory()->create();
        $loop = $this->loopWithActiveVersion(User::factory()->create());

        $this->actingAs($stranger)
            ->post(route('loops.actions.store', $loop), [
                'title' => 'Second meal check-in',
                'kind' => 'clock',
                'time' => '19:00',
                'recurrence' => 'daily',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('actions', ['title' => 'Second meal check-in']);
    }

    public function test_retiring_an_action_archives_it_and_keeps_its_history(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);
        $action = Action::factory()->for($loop, 'intention')->create([
            'strategy_id' => $loop->activeStrategy->id,
            'status' => Action::STATUS_ACTIVE,
        ]);
        $occurrence = $action->occurrences()->create([
            'scheduled_for' => now()->subDay(),
            'fired_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->delete(route('actions.destroy', $action))
            ->assertRedirect();

        $this->assertSame(Action::STATUS_ARCHIVED, $action->refresh()->status);
        $this->assertDatabaseHas('actions', ['id' => $action->id]);
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    public function test_a_stranger_cannot_retire_an_action(): void
    {
        $stranger = User::factory()->create();
        $loop = $this->loopWithActiveVersion(User::factory()->create());
        $action = Action::factory()->for($loop, 'intention')->create(['status' => Action::STATUS_ACTIVE]);

        $this->actingAs($stranger)
            ->delete(route('actions.destroy', $action))
            ->assertForbidden();

        $this->assertSame(Action::STATUS_ACTIVE, $action->refresh()->status);
    }
}
