<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CreateLoopTool;
use App\Mcp\Tools\GetLoopTool;
use App\Mcp\Tools\ListLoopsTool;
use App\Mcp\Tools\LogActionOutcomeTool;
use App\Mcp\Tools\LoopProgressTool;
use App\Mcp\Tools\TodayActionsTool;
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
each strategy version intervenes at one point of that chain. History is never
rewritten — a new version supersedes the previous one. Concrete to-dos are
"actions"; logging an outcome (completed / failed / skipped) is the core daily
interaction, and a failure must carry the user's reason.

Use list-loops / get-loop to see what the user is working on, today-actions to
see what is due, log-action-outcome to check things off, and loop-progress for
streaks and completion rates. Always ask the user for their reason before
logging a failed outcome.

Use create-loop when the user wants to start a new habit. Ask them for their
real cue, craving, response and reward and get their agreement on the
wording — do not invent the chain for them, because the loop only works if it
describes their actual behaviour. New loops are created paused; tell the user
to open the app to review and activate.
TEXT)]
class PatYourSelfServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListLoopsTool::class,
        GetLoopTool::class,
        TodayActionsTool::class,
        LogActionOutcomeTool::class,
        LoopProgressTool::class,
        CreateLoopTool::class,
    ];
}
