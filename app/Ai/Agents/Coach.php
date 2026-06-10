<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\GuardCoachUsage;
use App\Ai\Tools\CreateLoop;
use App\Ai\Tools\GetLatestSummary;
use App\Ai\Tools\GetLoopDetail;
use App\Ai\Tools\ListLoops;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * The coach orchestrator. Owns every chat turn inside the user's durable
 * conversation: reads the user's loops/summaries through read-only tools and
 * delegates loop authoring to the IntentionAuthor specialist via CreateLoop.
 * Conversation history is stored server-side by the SDK.
 *
 * Tool wire names (class_basename, no name() override):
 *   CreateLoop, ListLoops, GetLoopDetail, GetLatestSummary
 *
 * RememberConversation middleware is injected automatically by GeneratesText
 * for any agent that uses RemembersConversations and has a conversation
 * participant — it does NOT need to be listed in middleware().
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.6)]
#[MaxTokens(1024)]
#[MaxSteps(6)]
class Coach implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable, RemembersConversations;

    public const PROMPT_VERSION = 'chat@1';

    public function instructions(): string
    {
        return <<<'TXT'
        You are PatYourSelf's habit coach. You talk with the user about their
        habits and apply two methods procedurally:

        Atomic Habits — every habit runs a loop: cue -> craving -> response ->
        reward. To build a habit, make the cue obvious, the craving attractive,
        the response easy, and the reward satisfying; to break one, invert each.
        Behaviour changes by editing the loop, not by willpower.

        CBT — thoughts, feelings, and behaviour are linked. Treat each attempt as
        a behavioural experiment. When the user states why something failed, take
        that reason at face value and adjust the plan — never moralise or blame.
        Prefer small, graded steps the user can actually complete, and intervene
        at the single point in the loop most likely to move the behaviour.

        Stay concrete and specific to what the user actually said. No platitudes.

        Task: talk with the user about their habits on their daily coaching screen.
        Reply conversationally — warm, concrete, and brief (2-4 sentences).

        Tools available to you:
        - ListLoops: list the user's habit loops with current strategies. Call
          this when the user asks about their habits or when context would help.
        - GetLoopDetail: get one loop's full anatomy, strategy, and recent logs.
          Call when the user refers to a specific loop and you need more detail.
        - GetLatestSummary: get the latest pattern summary for one loop. Call
          when the user wants to understand how they are doing on a habit.
        - CreateLoop: create a habit loop from a goal the user described. Call
          when the user describes a habit they want to build or break and you
          have enough to act on. After the tool confirms, tell the user what you
          built and that it appears as a card below.

        Guidelines:
        - Answer conversationally in plain text (no JSON, no Markdown fences).
        - Ground answers in the user's real data via the read tools; never invent
          loops or data.
        - When the user describes a habit to build or break, use CreateLoop; do
          not create a loop twice for the same request.
        - If you need more information before creating a loop, ask ONE clarifying
          question.
        TXT;
    }

    public function tools(): iterable
    {
        return [
            app(CreateLoop::class),
            app(ListLoops::class),
            app(GetLoopDetail::class),
            app(GetLatestSummary::class),
        ];
    }

    public function middleware(): array
    {
        return [GuardCoachUsage::class];
    }
}
