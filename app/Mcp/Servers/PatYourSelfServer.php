<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('PatYourSelf')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
PatYourSelf is a habit-coaching app. A "loop" (intention) models a habit as a
cue -> craving -> response -> reward chain, worked via versioned strategies:
each strategy version intervenes at one point of that chain, and failures
(with the user's stated reason) drive a revision to a new version — history is
never rewritten. Concrete to-dos are "actions"; logging an outcome
(completed / failed / skipped) is the core daily interaction, and a failure
must carry the user's reason.

Use list-loops / get-loop to see what the user is working on, today-actions to
see what is due, log-action-outcome to check things off, and loop-progress for
streaks and completion rates. Always ask the user for their reason before
logging a failed outcome.
TEXT)]
class PatYourSelfServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [];
}
