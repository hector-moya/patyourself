<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\PatYourSelfServer;
use App\Mcp\Tools\LoopProgressTool;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class LoopProgressToolTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_reports_totals_and_completion_rate_for_the_whole_lifetime(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['title' => 'Meditate']);
        $action = Action::factory()->for($loop)->create([
            'status' => Action::STATUS_COMPLETED,
            'recurrence' => null,
            'scheduled_for' => null,
        ]);

        ActionLog::factory()->count(3)->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);
        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Too tired',
        ]);

        $response = PatYourSelfServer::actingAs($user)
            ->tool(LoopProgressTool::class, ['intention_id' => $loop->id]);

        $response->assertOk();

        $payload = $this->payload($response);

        $this->assertSame(['loop_id', 'title', 'current_version', 'lifetime'], array_keys($payload));
        $this->assertSame($loop->id, $payload['loop_id']);
        $this->assertSame('Meditate', $payload['title']);
        $this->assertSame(75, $payload['lifetime']['completion_rate']);
        $this->assertSame([
            'completed' => 3,
            'failed' => 1,
            'skipped' => 0,
        ], $payload['lifetime']['totals']);
    }

    /**
     * The block that tells a strategy which is failing from one which is
     * working: without it a fresh intervention drags every old outcome forward
     * and reads as though it inherited the last version's record.
     */
    public function test_the_current_version_block_counts_only_that_versions_outcomes(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        $first = Strategy::factory()->for($loop)->create(['version' => 1, 'status' => Strategy::STATUS_SUPERSEDED]);
        $second = Strategy::factory()->for($loop)->create(['version' => 2, 'status' => Strategy::STATUS_ACTIVE]);

        ActionLog::factory()->count(4)->for(Action::factory()->for($loop)->for($first))->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
        ActionLog::factory()->for(Action::factory()->for($loop)->for($second))->for($user)
            ->create(['outcome' => ActionLog::OUTCOME_FAILED, 'reason' => 'Second plate before I noticed']);

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopProgressTool::class, ['intention_id' => $loop->id]),
        );

        $this->assertSame(2, $payload['current_version']['version']);
        $this->assertSame(0, $payload['current_version']['completion_rate']);
        $this->assertSame(['completed' => 0, 'failed' => 1, 'skipped' => 0], $payload['current_version']['totals']);
        $this->assertSame(80, $payload['lifetime']['completion_rate']);
    }

    public function test_the_current_version_block_is_null_when_no_version_is_active(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopProgressTool::class, ['intention_id' => $loop->id]),
        );

        $this->assertNull($payload['current_version']);
    }

    /**
     * A skipped occasion never happened, so it cannot count against a strategy.
     * Its count still shows, so a thin denominator is visible rather than hidden.
     */
    public function test_skipped_occasions_stay_out_of_both_denominators(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create(['version' => 1, 'status' => Strategy::STATUS_ACTIVE]);
        $action = Action::factory()->for($loop)->for($strategy)->create();

        ActionLog::factory()->count(2)->for($action)->for($user)->create(['outcome' => ActionLog::OUTCOME_COMPLETED]);
        ActionLog::factory()->for($action)->for($user)->create([
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Did not think about it',
        ]);
        ActionLog::factory()->count(3)->for($action)->for($user)->create(['outcome' => ActionLog::OUTCOME_SKIPPED]);

        $payload = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopProgressTool::class, ['intention_id' => $loop->id]),
        );

        $this->assertSame(67, $payload['current_version']['completion_rate']);
        $this->assertSame(67, $payload['lifetime']['completion_rate']);
        $this->assertSame(3, $payload['current_version']['totals']['skipped']);
        $this->assertSame(3, $payload['lifetime']['totals']['skipped']);
    }

    public function test_the_current_version_block_reports_the_experiment_shape(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'review_at' => null,
        ]);

        $block = $this->payload(
            PatYourSelfServer::actingAs($user)->tool(LoopProgressTool::class, ['intention_id' => $loop->id]),
        )['current_version'];

        $this->assertSame([
            'version', 'started_at', 'day_of_experiment', 'planned_days', 'is_under_review',
            'verdict', 'streak', 'completion_rate', 'totals', 'last_logged_at',
        ], array_keys($block));
        // Open-ended: never a countdown, never a zero-day experiment.
        $this->assertNull($block['planned_days']);
        $this->assertFalse($block['is_under_review']);
    }

    public function test_rejects_another_users_loop(): void
    {
        $foreign = Intention::factory()->create();

        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class, ['intention_id' => $foreign->id])
            ->assertHasErrors(['Not found.']);
    }

    public function test_rejects_a_wholly_unknown_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class, ['intention_id' => 999999])
            ->assertHasErrors(['Not found.']);
    }

    public function test_requires_an_intention_id(): void
    {
        PatYourSelfServer::actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->tool(LoopProgressTool::class)
            ->assertHasErrors();
    }
}
