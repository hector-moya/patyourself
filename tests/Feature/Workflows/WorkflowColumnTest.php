<?php

namespace Tests\Feature\Workflows;

use App\Models\Action;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\TestCase;

/**
 * The one column every later module reuses. Null is the ordinary state, not a
 * missing value, so it is asserted as a value rather than as an absence.
 */
class WorkflowColumnTest extends TestCase
{
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerSpecFakeWorkflow();
    }

    public function test_a_loop_created_without_a_workflow_stores_null(): void
    {
        $intention = Intention::factory()->for(User::factory())->create();

        // Asserted against the column, not against its absence:
        // assertDatabaseMissing on a column SQLite cannot resolve degrades to a
        // string literal and passes forever.
        $this->assertDatabaseHas('intentions', [
            'id' => $intention->id,
            'workflow' => null,
        ]);
        $this->assertNull($intention->fresh()->workflow);
    }

    public function test_a_workflow_name_round_trips_through_the_column(): void
    {
        $intention = Intention::factory()
            ->for(User::factory())
            ->withWorkflow(self::SPEC_FAKE)
            ->create();

        $this->assertDatabaseHas('intentions', [
            'id' => $intention->id,
            'workflow' => self::SPEC_FAKE,
        ]);
        $this->assertSame(self::SPEC_FAKE, $intention->fresh()->workflow);
    }

    public function test_a_registered_workflow_may_be_set_on_a_loop(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/loops/'.$this->loopFor($user)->id, [
            'workflow' => self::SPEC_FAKE,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('intentions', [
            'user_id' => $user->id,
            'workflow' => self::SPEC_FAKE,
        ]);
    }

    public function test_a_workflow_the_registry_does_not_know_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);

        $this->actingAs($user)->patch('/loops/'.$loop->id, [
            'workflow' => 'gimnasio',
        ])->assertSessionHasErrors('workflow');

        // The rejection has to leave the column alone, not blank it — started
        // from a known value so this proves preservation rather than a null
        // that a no-op would have left untouched either way.
        $this->assertDatabaseHas('intentions', [
            'id' => $loop->id,
            'workflow' => self::SPEC_FAKE,
        ]);
    }

    public function test_a_loop_may_be_returned_to_no_workflow(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);

        $this->actingAs($user)->patch('/loops/'.$loop->id, [
            'workflow' => null,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('intentions', [
            'id' => $loop->id,
            'workflow' => null,
        ]);
    }

    public function test_a_registered_workflow_is_accepted_on_creation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/loops', $this->creationPayload([
            'workflow' => self::SPEC_FAKE,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('intentions', [
            'user_id' => $user->id,
            'workflow' => self::SPEC_FAKE,
        ]);
    }

    public function test_a_workflow_the_registry_does_not_know_is_rejected_on_creation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/loops', $this->creationPayload([
            'workflow' => 'gimnasio',
        ]))->assertSessionHasErrors('workflow');

        // The rejection has to block the whole creation, not just the field.
        $this->assertDatabaseCount('intentions', 0);
    }

    public function test_the_loop_payload_carries_the_workflow(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);

        $this->withoutVite()->actingAs($user)->get('/loops/'.$loop->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intention.workflow', self::SPEC_FAKE));
    }

    public function test_the_loop_payload_carries_null_for_a_plain_loop(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);

        $this->withoutVite()->actingAs($user)->get('/loops/'.$loop->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('intention.workflow', null));
    }

    public function test_the_catch_up_payload_carries_the_workflow(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user, self::SPEC_FAKE);
        $action = Action::factory()->for($loop, 'intention')->anchored()->create();
        $occurrence = Occurrence::factory()->for($action)->unlogged()->create();

        $this->withoutVite()->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.0.id', $occurrence->id)
                ->where('occurrences.0.workflow', self::SPEC_FAKE));
    }

    public function test_the_catch_up_payload_carries_null_for_a_plain_loop(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopFor($user);
        $action = Action::factory()->for($loop, 'intention')->anchored()->create();
        Occurrence::factory()->for($action)->unlogged()->create();

        $this->withoutVite()->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('occurrences.0.workflow', null));
    }

    private function loopFor(User $user, ?string $workflow = null): Intention
    {
        return Intention::factory()->for($user)->withWorkflow($workflow)->create();
    }

    /**
     * The manual-creation payload shape, matching
     * `IntentionWebCrudTest::payload()` so the two tests don't drift.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function creationPayload(array $overrides = []): array
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
}
