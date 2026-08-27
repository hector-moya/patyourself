<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\ConcludeExperimentTool;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ending an experiment from a conversation. Two things are load-bearing.
 *
 * Concluding is not superseding — a version concluded as `worked` keeps
 * running, and only start-experiment writes the next version.
 *
 * And the planned length survives. `review_at` is what plannedDays() derives
 * from, so a conclusion that cleared it would erase how long the experiment was
 * meant to run, with no `concluded_at` to recover it from.
 */
class ConcludeExperimentToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-27 09:00:00');
    }

    /**
     * @return array<mixed>
     */
    private function payload(TestResponse $response): array
    {
        $content = new \ReflectionMethod($response, 'content');

        /** @var array<int, string> $text */
        $text = $content->invoke($response);

        return json_decode($text[0], true, flags: JSON_THROW_ON_ERROR);
    }

    private function loopWithActiveVersion(User $user, ?CarbonImmutable $reviewAt = null): Intention
    {
        $loop = Intention::factory()->for($user)->create();

        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Put the fork down between mouthfuls',
            'created_at' => CarbonImmutable::parse('2026-08-13 09:00:00'),
            'review_at' => $reviewAt,
        ]);

        return $loop->refresh();
    }

    public function test_it_records_the_verdict_on_the_active_version(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_WORKED,
            'note' => 'the pause stuck once I put the fork down',
        ]);

        $response->assertOk();
        $payload = $this->payload($response);

        $this->assertSame($loop->id, $payload['loop_id']);
        $this->assertSame(1, $payload['version']);
        $this->assertSame(Strategy::VERDICT_WORKED, $payload['verdict']);
        $this->assertSame('the pause stuck once I put the fork down', $payload['verdict_note']);
        $this->assertDatabaseHas('strategies', [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_WORKED,
        ]);
    }

    /**
     * The owner's ruling, surfaced through the tool: a conclusion keeps the
     * planned length rather than erasing it.
     */
    public function test_concluding_keeps_the_planned_length(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user, CarbonImmutable::parse('2026-08-27 09:00:00'));

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_INCONCLUSIVE,
        ]);

        $response->assertOk();
        $payload = $this->payload($response);

        $this->assertNotNull($payload['review_at']);
        $this->assertSame(14, $payload['planned_days']);
        // Ran exactly its planned length: day 14 of a 14-day experiment.
        $this->assertSame(14, $payload['day_of_experiment']);
        $this->assertFalse($payload['is_under_review']);
    }

    public function test_an_open_ended_experiment_reports_no_planned_length(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_WORKED,
        ]);

        $response->assertOk();
        $payload = $this->payload($response);

        // Open-ended is a perfectly good state and must never render as a
        // countdown or a missed target.
        $this->assertNull($payload['review_at']);
        $this->assertNull($payload['planned_days']);
    }

    public function test_a_version_concluded_as_worked_keeps_running(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_WORKED,
        ]);

        $response->assertOk();

        // Concluding is not superseding: no new version, status untouched.
        $this->assertSame(Strategy::STATUS_ACTIVE, $this->payload($response)['status']);
        $this->assertSame(1, Strategy::query()->where('intention_id', $loop->id)->count());
    }

    /**
     * A failure carries its reason. Enforced at the tool boundary, the same
     * place start-experiment guards the intervention point.
     */
    public function test_it_refuses_a_failed_verdict_with_no_note(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_FAILED,
        ]);

        // The caller is a model, so the message has to say what to do about it.
        // Pinned because the custom message is easy to lose back to Laravel's
        // generic "The note field is required when verdict is failed."
        $response->assertHasErrors([
            'A failed verdict needs a note saying what the evidence showed. A failure is only useful to the next experiment if it carries its reason.',
        ]);
        $this->assertDatabaseMissing('strategies', [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_FAILED,
        ]);
    }

    public function test_it_accepts_a_failed_verdict_that_carries_its_reason(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        // Padded and mixed-case on purpose: a note that arrives already tidy
        // cannot tell a verbatim implementation from a trimming one.
        $note = '  the cue never actually fired ON work-from-home days.  ';

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_FAILED,
            'note' => $note,
        ]);

        $response->assertOk();

        // Verbatim. Never trimmed, squished or sentence-cased.
        $this->assertSame($note, $this->payload($response)['verdict_note']);
        $this->assertDatabaseHas('strategies', [
            'intention_id' => $loop->id,
            'verdict_note' => $note,
        ]);
    }

    /**
     * The `failed` guard must reject a note that is only whitespace, not just a
     * missing one — otherwise "  " would satisfy "a failure carries its reason".
     */
    public function test_it_refuses_a_failed_verdict_whose_note_is_only_whitespace(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_FAILED,
            'note' => '   ',
        ]);

        $response->assertHasErrors();
        $this->assertNull($loop->activeStrategy->refresh()->verdict);
    }

    public function test_it_rejects_an_unknown_verdict(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => 'sort-of-worked',
        ]);

        $response->assertHasErrors();
    }

    public function test_it_refuses_to_conclude_twice(): void
    {
        $user = User::factory()->create();
        $loop = $this->loopWithActiveVersion($user);

        $loop->activeStrategy->update(['verdict' => Strategy::VERDICT_WORKED]);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->refresh()->id,
            'verdict' => Strategy::VERDICT_FAILED,
            'note' => 'changed my mind',
        ]);

        $response->assertHasErrors();
    }

    public function test_it_errors_when_the_loop_has_no_active_version(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
        ]);

        $response = PatYourSelfServer::actingAs($user)->tool(ConcludeExperimentTool::class, [
            'intention_id' => $loop->id,
            'verdict' => Strategy::VERDICT_WORKED,
        ]);

        $response->assertHasErrors();
    }

    public function test_it_never_concludes_another_users_experiment(): void
    {
        $stranger = User::factory()->create();
        $loop = $this->loopWithActiveVersion($stranger);

        $response = PatYourSelfServer::actingAs(User::factory()->create())
            ->tool(ConcludeExperimentTool::class, [
                'intention_id' => $loop->id,
                'verdict' => Strategy::VERDICT_WORKED,
            ]);

        $response->assertHasErrors();
        $this->assertNull($loop->activeStrategy->refresh()->verdict);
    }
}
