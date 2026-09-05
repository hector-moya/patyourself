<?php

namespace Tests\Feature\Workflows;

use App\Models\Intention;
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
        $loop = $this->loopFor($user);

        $this->actingAs($user)->patch('/loops/'.$loop->id, [
            'workflow' => 'gimnasio',
        ])->assertSessionHasErrors('workflow');

        // The rejection has to leave the column alone, not blank it.
        $this->assertDatabaseHas('intentions', [
            'id' => $loop->id,
            'workflow' => null,
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

    private function loopFor(User $user, ?string $workflow = null): Intention
    {
        return Intention::factory()->for($user)->withWorkflow($workflow)->create();
    }
}
