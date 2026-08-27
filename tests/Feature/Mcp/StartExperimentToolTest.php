<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\StartExperimentTool;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * Starting the next experiment from a conversation. Two things are load-bearing
 * here: versions are append-only, and this tool is the *only* thing validating
 * a version's shape — ReviseStrategy::revise() used to do it and was deleted,
 * and AuthoredStrategy has no guard of its own.
 */
class StartExperimentToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
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

    private function loopWithActiveVersion(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Put the fork down between mouthfuls',
            'rationale' => 'Slowing the response should let fullness register',
        ]);
        Action::factory()->for($loop)->for($strategy)->create(['status' => Action::STATUS_ACTIVE]);

        return $loop;
    }

    public function test_it_supersedes_the_active_version_and_activates_the_next(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        $response = PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the pan back on the stove before sitting down',
            'rationale' => 'Removing the cue may work where slowing the response did not',
        ]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame(2, $payload['version']);
        $this->assertSame(Strategy::STATUS_ACTIVE, $payload['status']);
        $this->assertSame(Strategy::POINT_CUE, $payload['intervention_point']);
        $this->assertSame(1, $payload['superseded']['version']);

        $this->assertSame(Strategy::STATUS_SUPERSEDED, $loop->strategies()->where('version', 1)->firstOrFail()->status);
        $this->assertSame(2, $loop->activeStrategy()->firstOrFail()->version);
    }

    public function test_it_records_why_the_previous_version_stopped_being_right(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the pan back on the stove before sitting down',
            'supersedes_reason' => 'Slowing the response never got a chance — the second plate was served before anyone sat down',
        ])->assertOk();

        $this->assertSame(
            'Slowing the response never got a chance — the second plate was served before anyone sat down',
            $loop->strategies()->where('version', 1)->firstOrFail()->superseded_reason,
        );
    }

    public function test_a_review_after_days_sets_the_planned_length(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
                'intention_id' => $loop->id,
                'intervention_point' => Strategy::POINT_CUE,
                'approach' => 'Put the pan back on the stove before sitting down',
                'review_after_days' => 21,
            ]),
        );

        $this->assertSame(21, $payload['planned_days']);
        $this->assertStringStartsWith('2026-09-16T21:00:00', $payload['review_at']);
    }

    public function test_omitting_review_after_days_leaves_the_experiment_open_ended(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
                'intention_id' => $loop->id,
                'intervention_point' => Strategy::POINT_CUE,
                'approach' => 'Put the pan back on the stove before sitting down',
            ]),
        );

        $this->assertNull($payload['review_at']);
        $this->assertNull($payload['planned_days']);
    }

    public function test_it_defaults_the_change_reason_to_restrategized_on_failure(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the pan back on the stove before sitting down',
        ])->assertOk();

        $this->assertSame(
            Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            $loop->strategies()->where('version', 2)->firstOrFail()->change_reason,
        );
    }

    /**
     * The guard that lost its home when ReviseStrategy was deleted. Without it
     * a malformed intervention point goes straight into the database.
     */
    public function test_it_rejects_an_intervention_point_outside_the_chain(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => 'willpower',
            'approach' => 'Try harder',
        ])->assertHasErrors();

        $this->assertDatabaseCount('strategies', 1);
    }

    public function test_it_rejects_a_blank_approach(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => '   ',
        ])->assertHasErrors();

        $this->assertDatabaseCount('strategies', 1);
    }

    public function test_it_rejects_a_negative_review_after_days(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the pan back on the stove before sitting down',
            'review_after_days' => -3,
        ])->assertHasErrors();

        $this->assertDatabaseCount('strategies', 1);
    }

    public function test_it_errors_when_the_loop_has_no_active_version(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the pan back on the stove before sitting down',
        ])->assertHasErrors(['That loop has no active strategy version to supersede.']);
    }

    public function test_it_never_edits_the_previous_version_in_place(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveVersion($user);
        $before = $loop->strategies()->where('version', 1)->firstOrFail();

        PatYourSelfServer::actingAs($user)->tool(StartExperimentTool::class, [
            'intention_id' => $loop->id,
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Put the pan back on the stove before sitting down',
            'rationale' => 'Removing the cue may work where slowing the response did not',
        ])->assertOk();

        $after = $before->fresh();

        $this->assertSame($before->approach, $after->approach);
        $this->assertSame($before->intervention_point, $after->intervention_point);
        $this->assertSame($before->rationale, $after->rationale);
        $this->assertSame(2, $loop->strategies()->count());
    }

    public function test_it_rejects_another_users_loop(): void
    {
        $loop = $this->loopWithActiveVersion(User::factory()->create(['timezone' => 'UTC']));

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(StartExperimentTool::class, [
                'intention_id' => $loop->id,
                'intervention_point' => Strategy::POINT_CUE,
                'approach' => 'Put the pan back on the stove before sitting down',
            ])
            ->assertHasErrors(['Not found.']);

        $this->assertDatabaseCount('strategies', 1);
    }
}
