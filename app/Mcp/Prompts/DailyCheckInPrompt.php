<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;

#[Name('daily-check-in')]
#[Description(<<<'TEXT'
Start a check-in: work through the occasions that have passed without an
outcome, in the user's own words, then read back what the reasons show.
Carries the sequence, not the record — the tools still supply the data.
TEXT)]
class DailyCheckInPrompt extends Prompt
{
    /**
     * Seed a check-in.
     *
     * No arguments and no database access: this returns the workflow and the
     * rules that bind it, and the coach calls the tools for the record. A
     * payload embedded here would be a second place that has to stay honest
     * about "not overdue", and it would be stale the moment it was fetched.
     *
     * `arguments()` is deliberately not overridden — the inherited default is
     * empty, and in a client an argument renders as a form field the user has
     * to fill before the prompt fires. This one fires on a click.
     *
     * @return array<int, Response>
     */
    public function handle(Request $request): array
    {
        return [
            Response::text(<<<'TEXT'
                Open the user's check-in. The app is the record; you are the coach.

                1. pending-outcomes — the occasions that have passed with no outcome yet.
                   Present them as a short list, not an audit. Nothing here is overdue and
                   nothing expires. If there are none, say so plainly and read the record
                   instead — a check-in with nothing pending is a good check-in.
                2. Ask how each went, one loop at a time, and log-outcome as they answer. A
                   failed outcome must carry their stated reason in their own words, passed
                   through unchanged. skipped means the occasion never happened — no meal,
                   travelling, ill. If it happened and the strategy did not hold, including
                   not thinking about it, that is failed.
                3. log-note anything they mention that is not an outcome.
                4. loop-outcomes where the answers were interesting, to read the reasons back
                   and see where the chain is actually breaking.
                5. loop-progress and get-loop to see how a running experiment is holding up.

                If a version is past its review_at, say so and offer the review — do not run
                it here.

                Never count the backlog back at them, never propose a numeric target, and keep
                every failure about the strategy rather than about the user.
                TEXT)->asAssistant(),
            Response::text("Let's do my check-in."),
        ];
    }
}
