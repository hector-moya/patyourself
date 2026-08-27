<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Prompts\ReviewExperimentPrompt;
use App\Mcp\Servers\PatYourSelfServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The entry point for reviewing an experiment that has reached its review date.
 * The ordering is load-bearing: the verdict comes before the reflection,
 * because the reflection describes a record that now contains the verdict.
 *
 * Several expectations below span a line break, because the prompt text wraps
 * mid-phrase. If the wrapping moves, re-wrap the expectation rather than
 * weakening it to a single word that would match almost anything.
 */
class ReviewExperimentPromptTest extends TestCase
{
    use RefreshDatabase;

    private function prompt(): TestResponse
    {
        return PatYourSelfServer::actingAs(User::factory()->create())
            ->prompt(ReviewExperimentPrompt::class);
    }

    /**
     * TestResponse::content() is protected, so reach it the way the tool tests
     * already do rather than re-rendering the prompt by hand.
     */
    private function renderedText(): string
    {
        $response = $this->prompt();

        /** @var array<int, string> $content */
        $content = (new ReflectionMethod($response, 'content'))->invoke($response);

        return implode("\n", $content);
    }

    public function test_it_advertises_itself_under_its_documented_name(): void
    {
        $this->prompt()
            ->assertOk()
            ->assertHasNoErrors()
            ->assertName('review-experiment');
    }

    public function test_it_carries_a_description(): void
    {
        $this->prompt()->assertDescription(<<<'TEXT'
            Review an experiment that has reached its review date: reach a verdict on the
            evidence, update the loop's rolling narrative, and leave the next experiment to
            the owner.
            TEXT);
    }

    public function test_it_names_the_tools_the_review_walks_through(): void
    {
        $this->prompt()->assertSee([
            'list-loops',
            'loop-progress',
            'loop-outcomes',
            'get-loop',
            'conclude-experiment',
            'write-reflection',
            'start-experiment',
        ]);
    }

    /**
     * Finding the loop is the prompt's job, not the user's — which is why it
     * takes no arguments. And when nothing is ready, it stops: a review that
     * manufactures a verdict on an experiment still running is worse than none.
     */
    public function test_it_finds_the_loop_itself_and_stops_when_none_is_ready(): void
    {
        $this->prompt()->assertSee([
            'past its review_at',
            'If none is ready, say so and stop',
            'If more than one',
        ]);
    }

    /**
     * A version is judged on its own run, not on the loop's lifetime record —
     * otherwise a loop with a bad history can never show a working strategy.
     */
    public function test_it_judges_the_version_on_its_own_evidence(): void
    {
        $this->prompt()->assertSee('on its own evidence, not the loop');
    }

    /**
     * Thin evidence is a finding. Forcing a verdict the record does not support
     * is how the notebook starts lying to its owner.
     */
    public function test_it_treats_inconclusive_as_a_real_answer(): void
    {
        $this->prompt()->assertSee([
            "inconclusive is a\n   real answer",
            "Thin evidence is a\n   finding",
        ]);
    }

    public function test_it_requires_a_failed_verdict_to_carry_its_reason(): void
    {
        $this->prompt()->assertSee([
            'A failed verdict must carry a note saying what the evidence showed',
            'about the strategy rather than the user',
        ]);
    }

    /**
     * Concluding is not superseding. A version concluded as worked stays active
     * and keeps running, so the next experiment is the owner's decision.
     */
    public function test_it_offers_the_next_experiment_rather_than_assuming_it(): void
    {
        $this->prompt()->assertSee([
            "concluded as\n   worked stays active and keeps running",
            "a separate\n   decision, and it is theirs",
        ]);
    }

    /**
     * The reflection is a synthesis of what the record now shows, and the record
     * does not contain the verdict until conclude-experiment has run. Written as
     * an offset comparison because assertSee cannot express ordering.
     */
    public function test_it_reaches_the_verdict_before_writing_the_reflection(): void
    {
        $text = $this->renderedText();

        $verdict = strpos($text, 'conclude-experiment');
        $reflection = strpos($text, 'write-reflection');

        $this->assertIsInt($verdict, 'The prompt never names conclude-experiment.');
        $this->assertIsInt($reflection, 'The prompt never names write-reflection.');
        $this->assertLessThan(
            $reflection,
            $verdict,
            'The reflection describes a record that must already carry the verdict.',
        );
    }

    public function test_it_takes_no_arguments(): void
    {
        $this->assertSame([], (new ReviewExperimentPrompt)->arguments());
    }
}
