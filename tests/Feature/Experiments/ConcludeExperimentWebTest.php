<?php

namespace Tests\Feature\Experiments;

use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConcludeExperimentWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_concludes_an_experiment_with_a_verdict(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create([
            'status' => Strategy::STATUS_ACTIVE,
            'verdict' => null,
        ]);

        $this->actingAs($user)
            ->post(route('strategies.verdict.store', $strategy), [
                'verdict' => Strategy::VERDICT_WORKED,
            ])
            ->assertRedirect();

        $this->assertSame(Strategy::VERDICT_WORKED, $strategy->refresh()->verdict);
    }

    public function test_a_failed_verdict_requires_a_note(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create(['verdict' => null]);

        $this->actingAs($user)
            ->post(route('strategies.verdict.store', $strategy), [
                'verdict' => Strategy::VERDICT_FAILED,
            ])
            ->assertSessionHasErrors('note');

        $this->assertNull($strategy->refresh()->verdict);
    }

    public function test_the_note_is_stored_verbatim(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create(['verdict' => null]);
        $note = '  The cue never fired.   Mornings are the wrong anchor.  ';

        $this->actingAs($user)->post(route('strategies.verdict.store', $strategy), [
            'verdict' => Strategy::VERDICT_FAILED,
            'note' => $note,
        ]);

        $this->assertSame($note, $strategy->refresh()->verdict_note);
    }

    public function test_a_stranger_cannot_conclude_someone_elses_experiment(): void
    {
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for(User::factory())->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create(['verdict' => null]);

        $this->actingAs($stranger)
            ->post(route('strategies.verdict.store', $strategy), ['verdict' => Strategy::VERDICT_WORKED])
            ->assertForbidden();

        $this->assertNull($strategy->refresh()->verdict);
    }

    public function test_concluding_an_already_concluded_experiment_is_a_validation_error(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop, 'intention')->create([
            'verdict' => Strategy::VERDICT_WORKED,
        ]);

        $this->actingAs($user)
            ->post(route('strategies.verdict.store', $strategy), [
                'verdict' => Strategy::VERDICT_FAILED,
                'note' => 'This should fail because already concluded.',
            ])
            ->assertSessionHasErrors('verdict');

        $this->assertSame(Strategy::VERDICT_WORKED, $strategy->refresh()->verdict);
    }

    public function test_guests_are_redirected(): void
    {
        $strategy = Strategy::factory()->create();

        $this->post(route('strategies.verdict.store', $strategy), ['verdict' => Strategy::VERDICT_WORKED])
            ->assertRedirect(route('login'));
    }
}
