<?php

namespace Tests\Feature;

use App\Actions\AuthorIntention;
use App\Actions\ReviseStrategy;
use App\Actions\UpdateRollingSummary;
use App\Ai\Agents\Summarizer;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\FakeCoachService;
use App\Services\Coach\Prompts\CoachPrompts;
use App\Services\Coach\Strategy\StrategyRevisionSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The system prompts are versioned, and every artifact the coach authors
 * records which prompt version produced it — provenance for when prompts change.
 */
class PromptVersioningTest extends TestCase
{
    use RefreshDatabase;

    private FakeCoachService $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = new FakeCoachService;
        $this->app->instance(CoachService::class, $this->coach);
    }

    public function test_authored_intention_records_the_prompt_version(): void
    {
        $this->coach->pushJson([
            'title' => 'Morning walk',
            'description' => 'Start the day moving.',
            'type' => 'build',
            'cue' => 'Coffee brews',
            'craving' => 'Feel awake',
            'response' => 'Walk 15 minutes',
            'reward' => 'Energy',
        ]);

        $intention = app(AuthorIntention::class)->handle(User::factory()->create(), 'more energy');

        $this->assertSame(
            CoachPrompts::intentionAuthoring()->version,
            $intention->metadata['prompt_version'],
        );
    }

    public function test_revised_strategy_records_the_prompt_version(): void
    {
        $intention = Intention::factory()->create();
        $current = Strategy::factory()->initial()->for($intention)->create();
        $this->coach->pushJson([
            'intervention_point' => Strategy::POINT_CUE,
            'approach' => 'Lay shoes out the night before.',
            'rationale' => 'Make the cue obvious.',
        ]);

        $next = app(ReviseStrategy::class)->restrategizeOnFailure($current, 'too tired');

        $this->assertSame(
            CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_RESTRATEGIZE)->version,
            $next->metadata['prompt_version'],
        );
    }

    public function test_rolling_summary_records_the_prompt_version(): void
    {
        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->for($strategy)->create();
        ActionLog::factory()->for($action)->create([
            'user_id' => $intention->user_id,
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'logged_at' => '2026-06-01 22:00:00',
        ]);
        Summarizer::fake([['content' => 'Summary.', 'patterns' => []]]);

        $summary = app(UpdateRollingSummary::class)->handle($intention);

        $this->assertSame(
            Summarizer::PROMPT_VERSION,
            $summary->metadata['prompt_version'],
        );
    }
}
