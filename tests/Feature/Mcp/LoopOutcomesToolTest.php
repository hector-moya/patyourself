<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LoopOutcomesTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The reasons, finally readable. loop-progress returns aggregates; this is the
 * raw material the next experiment gets written from, so the assertions here
 * are about fidelity: the user's words unchanged, dated by the occasion, and
 * attributed to the experiment that was running at the time.
 */
class LoopOutcomesToolTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function logOccasion(Action $action, User $user, string $occurredAt, array $attributes): ActionLog
    {
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => $occurredAt,
        ]);

        return ActionLog::factory()->create([
            'action_id' => $action->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'logged_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_it_returns_the_reason_exactly_as_the_user_said_it(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();
        $reason = "  didn't Think about it AT ALL.  ";

        $this->logOccasion($action, $user, '2026-08-24 19:00:00', [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => $reason,
        ]);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id]);

        $response->assertOk();

        $this->assertSame($reason, $this->payload($response)['outcomes'][0]['reason']);
    }

    public function test_it_dates_an_outcome_by_the_occasion_not_by_when_it_was_typed(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        $this->logOccasion($action, $user, '2026-08-21 19:00:00', [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Second plate before I noticed',
        ]);

        $entry = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id]),
        )['outcomes'][0];

        $this->assertStringStartsWith('2026-08-21T19:00:00', $entry['occurred_at']);
        $this->assertStringStartsWith('2026-08-26T21:00:00', $entry['logged_at']);
    }

    public function test_it_names_the_strategy_version_that_was_running_at_the_time(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        $first = Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_SUPERSEDED,
            'intervention_point' => Strategy::POINT_RESPONSE,
        ]);
        $second = Strategy::factory()->for($loop)->create([
            'version' => 2,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_CUE,
        ]);

        $this->logOccasion(Action::factory()->for($loop)->for($first)->create(), $user, '2026-08-20 19:00:00', [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Kept going past full',
        ]);
        $this->logOccasion(Action::factory()->for($loop)->for($second)->create(), $user, '2026-08-25 19:00:00', [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'reason' => null,
        ]);

        $outcomes = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id]),
        )['outcomes'];

        $this->assertSame(2, $outcomes[0]['strategy_version']);
        $this->assertSame(Strategy::POINT_CUE, $outcomes[0]['intervention_point']);
        $this->assertSame(1, $outcomes[1]['strategy_version']);
        $this->assertSame(Strategy::POINT_RESPONSE, $outcomes[1]['intervention_point']);
    }

    public function test_it_returns_context_and_its_structured_fields(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        $this->logOccasion($action, $user, '2026-08-24 19:00:00', [
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Kept going past full',
            'context' => 'Standing at the bench, plate refilled straight away',
            'context_fields' => ['place' => 'kitchen', 'with_others' => false, 'preceded_by' => 'skipped lunch'],
        ]);

        $entry = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id]),
        )['outcomes'][0];

        $this->assertSame('Standing at the bench, plate refilled straight away', $entry['context']);
        $this->assertSame('kitchen', $entry['context_fields']['place']);
        $this->assertFalse($entry['context_fields']['with_others']);
    }

    public function test_it_reads_newest_occasion_first(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        $this->logOccasion($action, $user, '2026-08-20 19:00:00', ['outcome' => ActionLog::OUTCOME_COMPLETED]);
        $this->logOccasion($action, $user, '2026-08-25 19:00:00', ['outcome' => ActionLog::OUTCOME_SKIPPED]);

        $outcomes = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id]),
        )['outcomes'];

        $this->assertSame(ActionLog::OUTCOME_SKIPPED, $outcomes[0]['outcome']);
        $this->assertSame(ActionLog::OUTCOME_COMPLETED, $outcomes[1]['outcome']);
    }

    public function test_since_filters_by_the_occasion_datetime(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop)->create();

        // Both were typed just now; only the occasions differ.
        $this->logOccasion($action, $user, '2026-08-10 19:00:00', ['outcome' => ActionLog::OUTCOME_COMPLETED]);
        $this->logOccasion($action, $user, '2026-08-25 19:00:00', ['outcome' => ActionLog::OUTCOME_COMPLETED]);

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopOutcomesTool::class, [
                'intention_id' => $loop->id,
                'since' => '2026-08-20T00:00:00+00:00',
            ]),
        );

        $this->assertSame(1, $payload['count']);
        $this->assertStringStartsWith('2026-08-25T19:00:00', $payload['outcomes'][0]['occurred_at']);
    }

    public function test_it_omits_outcomes_from_another_loop(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $other = Intention::factory()->for($user)->create();

        $this->logOccasion(Action::factory()->for($other)->create(), $user, '2026-08-24 19:00:00', [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id]),
        );

        $this->assertSame(0, $payload['count']);
        $this->assertSame([], $payload['outcomes']);
    }

    public function test_it_rejects_another_users_loop(): void
    {
        $loop = Intention::factory()->for(User::factory()->create(['timezone' => 'UTC']))->create();

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopOutcomesTool::class, ['intention_id' => $loop->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_it_requires_a_loop_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopOutcomesTool::class, [])
            ->assertHasErrors();
    }
}
