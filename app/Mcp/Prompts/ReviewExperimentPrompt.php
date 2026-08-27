<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('review-experiment')]
#[Description(<<<'TEXT'
Review an experiment that has reached its review date: reach a verdict on the
evidence, update the loop's rolling narrative, and leave the next experiment to
the owner.
TEXT)]
class ReviewExperimentPrompt extends Prompt
{
    /**
     * Seed a review.
     *
     * Takes no loop argument on purpose, so `arguments()` is left inherited. In
     * a client an argument renders as a form field filled before the prompt
     * fires, which would mean naming the loop before the coach has told you
     * anything — and it forecloses the "what is ready?" opening that is the
     * point of the prompt.
     *
     * The step order is load-bearing. The verdict precedes the reflection
     * because the reflection is a synthesis of what the record now shows, and
     * the record does not carry the verdict until conclude-experiment has run.
     * The next experiment comes last and is an offer: concluding is not
     * superseding, and a version concluded as `worked` stays active.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text(<<<'TEXT'
                Review the experiment that is ready for a verdict.

                1. list-loops. Find active loops whose current version is past its review_at.
                   If none is ready, say so and stop — nothing is overdue. If more than one
                   is, ask which.
                2. loop-progress. Judge the version on its own evidence, not the loop's
                   lifetime record.
                3. loop-outcomes to read the user's reasons verbatim; get-loop for the chain
                   and any notes.
                4. Say what the evidence shows and what it does not. Thin evidence is a
                   finding, not a gap to paper over.
                5. conclude-experiment — worked, failed or inconclusive. inconclusive is a
                   real answer when the experiment did not run long or cleanly enough to
                   judge. A failed verdict must carry a note saying what the evidence showed,
                   about the strategy rather than the user.
                6. write-reflection to update the loop's rolling narrative.
                7. Only then ask whether they want a new experiment. A version concluded as
                   worked stays active and keeps running — starting the next is a separate
                   decision, and it is theirs. If they do, start-experiment on the point in
                   the chain their reasons actually implicate.
                TEXT)->asAssistant(),
            Response::text("Let's review my experiment."),
        ];
    }
}
