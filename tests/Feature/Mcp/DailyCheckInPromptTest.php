<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Prompts\DailyCheckInPrompt;
use App\Mcp\Servers\PatYourSelfServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The entry point for a check-in. It carries the sequence and the rules that
 * bind it — not the record, which the tools still supply.
 */
class DailyCheckInPromptTest extends TestCase
{
    use RefreshDatabase;

    private function prompt(): TestResponse
    {
        return PatYourSelfServer::actingAs(User::factory()->create())
            ->prompt(DailyCheckInPrompt::class);
    }

    public function test_it_advertises_itself_under_its_documented_name(): void
    {
        $this->prompt()
            ->assertOk()
            ->assertHasNoErrors()
            ->assertName('daily-check-in');
    }

    public function test_it_carries_a_description(): void
    {
        $this->prompt()->assertDescription(<<<'TEXT'
            Start a check-in: work through the occasions that have passed without an
            outcome, in the user's own words, then read back what the reasons show.
            Carries the sequence, not the record — the tools still supply the data.
            TEXT);
    }

    /**
     * The sequence is the point of the prompt. Each of these is a real tool,
     * which McpEndpointTest asserts separately against the registered list.
     */
    public function test_it_names_the_tools_the_check_in_walks_through(): void
    {
        $this->prompt()->assertSee([
            'pending-outcomes',
            'log-outcome',
            'log-note',
            'loop-outcomes',
            'loop-progress',
            'get-loop',
        ]);
    }

    /**
     * A reason that gets paraphrased is not evidence. This is the rule the next
     * experiment gets written from, so it has to survive in the prompt text.
     *
     * The second expectation spans a line break because the prompt text wraps
     * mid-phrase. If the wrapping moves, re-wrap this expectation rather than
     * weakening it to a single word that would match almost anything.
     */
    public function test_it_requires_a_failed_outcome_to_carry_the_users_own_words(): void
    {
        $this->prompt()->assertSee([
            'must carry their stated reason in their own words',
            "passed\n   through unchanged",
        ]);
    }

    /**
     * skipped and failed are not interchangeable: skipped leaves the occasion
     * out of the completion-rate denominator entirely.
     */
    public function test_it_distinguishes_a_skipped_occasion_from_a_failed_one(): void
    {
        $this->prompt()->assertSee([
            'skipped means the occasion never happened',
            'not thinking about it, that is failed',
        ]);
    }

    /**
     * The notebook never nags. A backlog is a list to work through, not debt.
     */
    public function test_it_forbids_presenting_the_backlog_as_debt(): void
    {
        $this->prompt()->assertSee([
            'Nothing here is overdue',
            'Never count the backlog back at them',
            'never propose a numeric target',
            'about the strategy rather than about the user',
        ]);
    }

    /**
     * A review is its own workflow with its own prompt. The check-in hands off
     * rather than swallowing it.
     */
    public function test_it_offers_the_review_rather_than_running_it(): void
    {
        $this->prompt()->assertSee('offer the review — do not run');
    }

    /**
     * In Claude Desktop an argument renders as a form field the user fills
     * before the prompt fires. This one fires on a click.
     */
    public function test_it_takes_no_arguments(): void
    {
        $this->assertSame([], (new DailyCheckInPrompt)->arguments());
    }
}
