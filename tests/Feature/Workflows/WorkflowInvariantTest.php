<?php

namespace Tests\Feature\Workflows;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use App\Services\Workflows\WorkflowRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\TestCase;

/**
 * The rule the whole idea rests on: one occasion produces exactly one
 * ActionLog, whatever a workflow recorded.
 *
 * The moment a workflow can mint fuel faster by being more granular, the app
 * has to hold a position on what each module is worth, and every new module
 * reopens the argument. Every workflow that follows must add its own version of
 * the guard below.
 */
class WorkflowInvariantTest extends TestCase
{
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->registerSpecFakeWorkflow();
    }

    public function test_recording_at_either_attachment_site_creates_no_log(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $definition = app(WorkflowRegistry::class)->for(self::SPEC_FAKE);
        $this->assertNotNull($definition);

        // Configuration on the action: what the occasion is meant to contain.
        $definition->config::query()->create([
            'action_id' => $action->id,
            'body' => 'three of the thing',
        ]);

        // A record on the occurrence, forty times over: what it actually
        // contained. A granular workflow must not out-earn a glass of water.
        for ($set = 1; $set <= 40; $set++) {
            $definition->record::query()->create([
                'occurrence_id' => $occurrence->id,
                'body' => 'set '.$set,
            ]);
        }

        $this->assertSame(40, $definition->record::query()->count());
        $this->assertSame(1, $definition->config::query()->count());

        // Nothing above pressed a verdict, so nothing above is worth anything.
        $this->assertSame(0, ActionLog::query()->count());
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_one_occasion_produces_exactly_one_log_however_much_it_recorded(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $definition = app(WorkflowRegistry::class)->for(self::SPEC_FAKE);

        for ($set = 1; $set <= 40; $set++) {
            $definition->record::query()->create([
                'occurrence_id' => $occurrence->id,
                'body' => 'set '.$set,
            ]);
        }

        $this->actingAs($user)
            ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
            ->assertSessionHasNoErrors();

        // A second verdict on the same occasion is refused, not appended.
        $this->actingAs($user)
            ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
            ->assertSessionHasErrors('outcome');

        $this->assertSame(1, ActionLog::query()->count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);

        // Forty sets and a glass of water are worth the same, and the record
        // survives the verdict either way.
        $this->assertSame(40, $definition->record::query()->count());
    }

    public function test_a_failed_occasion_still_carries_what_was_recorded(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        $action = Action::factory()->for($loop, 'intention')->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $definition = app(WorkflowRegistry::class)->for(self::SPEC_FAKE);
        $definition->record::query()->create([
            'occurrence_id' => $occurrence->id,
            'body' => 'the two things managed before it went wrong',
        ]);

        $this->actingAs($user)->post('/occurrences/'.$occurrence->id.'/logs', [
            'outcome' => 'failed',
            'reason' => 'cut it short',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $definition->record::query()->where('occurrence_id', $occurrence->id)->count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);
    }
}
