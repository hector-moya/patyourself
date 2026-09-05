<?php

namespace Tests\Feature\Workflows;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Workflows\RegistersSpecFakeWorkflow;
use Tests\TestCase;

/**
 * The claim this whole design rests on is that nothing was special-cased. That
 * is only true if it is pinned, so this walks a plain loop through its entire
 * life — schedule, fall due, appear in catch-up, log, count toward Blob — and
 * then walks a workflow loop through the same life and asserts the two records
 * are indistinguishable.
 *
 * The parity half is the sharper of the two: a plain-loop assertion can be made
 * to pass by a bug that skips workflow loops entirely, and only comparing the
 * two catches that.
 */
class PlainLoopIsUnchangedTest extends TestCase
{
    use RefreshDatabase;
    use RegistersSpecFakeWorkflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->registerSpecFakeWorkflow();
    }

    public function test_a_plain_loop_falls_due_catches_up_and_logs_as_it_always_has(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(null)->create();
        $action = Action::factory()->for($loop, 'intention')->anchored()->create();
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subDay(),
        ]);

        $this->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('occurrences.0.id', $occurrence->id));

        $this->actingAs($user)
            ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ActionLog::query()->where('occurrence_id', $occurrence->id)->count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);

        // The column is still what it was. Nothing on the logging path writes
        // to it, and nothing defaults it.
        $this->assertDatabaseHas('intentions', ['id' => $loop->id, 'workflow' => null]);
    }

    public function test_a_workflow_loop_and_a_plain_loop_leave_the_same_record(): void
    {
        $user = User::factory()->create();

        $plain = $this->loopWithADueOccasion($user, null, now()->subDays(2));
        $workflow = $this->loopWithADueOccasion($user, self::SPEC_FAKE, now()->subDay());

        $this->actingAs($user)->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('occurrences', 2));

        foreach ([$plain, $workflow] as $occurrence) {
            $this->actingAs($user)
                ->post('/occurrences/'.$occurrence->id.'/logs', ['outcome' => 'completed'])
                ->assertSessionHasNoErrors();
        }

        // One occasion, one log — on both, and the same one.
        $this->assertSame(1, ActionLog::query()->where('occurrence_id', $plain->id)->count());
        $this->assertSame(1, ActionLog::query()->where('occurrence_id', $workflow->id)->count());

        // Blob is never told which was which.
        $this->assertSame(2, app(CompanionResolver::class)->forUser($user)->logCount);
    }

    public function test_a_workflow_survives_a_strategy_revision(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->withWorkflow(self::SPEC_FAKE)->create();
        Strategy::factory()->initial()->for($loop, 'intention')->create([
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Walk for 15 minutes after coffee.',
        ]);

        $this->actingAs($user)
            ->post('/loops/'.$loop->id.'/experiments', $this->experimentPayload())
            ->assertSessionHasNoErrors();

        // The plan changed; the routing did not.
        $this->assertSame(self::SPEC_FAKE, $loop->fresh()->workflow);
    }

    private function loopWithADueOccasion(User $user, ?string $workflow, \DateTimeInterface|string $scheduledFor): Occurrence
    {
        $loop = Intention::factory()->for($user)->withWorkflow($workflow)->create();
        $action = Action::factory()->for($loop, 'intention')->anchored()->create();

        return Occurrence::factory()->for($action)->create([
            'scheduled_for' => $scheduledFor,
        ]);
    }

    /**
     * Copied verbatim from StartExperimentWebTest::test_the_owner_starts_the_next_experiment,
     * rather than invented — StoreExperimentRequest decides the shape, and that
     * test is the boundary's own proof that this payload is accepted.
     *
     * @return array<string, mixed>
     */
    private function experimentPayload(): array
    {
        return [
            'intervention_point' => Strategy::POINT_CRAVING,
            'approach' => 'Name the craving out loud before opening the app.',
            'rationale' => 'The cue is unavoidable, so the cue is the wrong place to intervene.',
            'supersedes_reason' => 'Removing the cue did not survive contact with a working day.',
            'review_after_days' => 14,
            'cadence' => 'keep',
        ];
    }
}
