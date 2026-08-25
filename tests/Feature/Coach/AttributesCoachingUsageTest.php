<?php

namespace Tests\Feature\Coach;

use App\Actions\UpdateRollingSummary;
use App\Ai\Agents\Summarizer;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttributesCoachingUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_rolling_summary_bills_the_loop_owner(): void
    {
        Summarizer::fake([['content' => 'A pattern.', 'patterns' => []]]);

        $intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($intention)->create();
        $action = Action::factory()->for($intention)->for($strategy)->create();
        ActionLog::factory()->for($action)->for($intention->user)->create([
            'outcome' => ActionLog::OUTCOME_COMPLETED,
            'logged_at' => now(),
        ]);

        app(UpdateRollingSummary::class)->handle($intention);

        Summarizer::assertPrompted(
            fn ($prompt) => $prompt->agent->conversationParticipant()?->is($intention->user) === true,
        );
    }
}
