<?php

namespace Tests\Feature\Workflows;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one column every later module reuses. Null is the ordinary state, not a
 * missing value, so it is asserted as a value rather than as an absence.
 */
class WorkflowColumnTest extends TestCase
{
    use RefreshDatabase;

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
            ->withWorkflow('spec-fake')
            ->create();

        $this->assertDatabaseHas('intentions', [
            'id' => $intention->id,
            'workflow' => 'spec-fake',
        ]);
        $this->assertSame('spec-fake', $intention->fresh()->workflow);
    }
}
