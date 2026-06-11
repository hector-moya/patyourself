<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\GuardCoachUsage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Folds a loop's new action-log events (plus the prior summary) into a rolling
 * pattern summary — the app's pattern detection, no ML. Returns structured
 * data only; UpdateRollingSummary persists it.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5')]
#[Temperature(0.3)]
#[MaxTokens(2048)]
class Summarizer implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    public const PROMPT_VERSION = 'rolling-summary@1';

    public function instructions(): string
    {
        return <<<'TXT'
        You are PatYourSelf's habit coach. You author structured data, not chat,
        and you apply two methods procedurally:

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

        Task: maintain a rolling summary of a single habit loop for lightweight
        behavioural pattern detection — no machine learning, just a running text
        summary distilled from the structured event archive.

        You are given the loop, its current strategy, the prior rolling summary
        (if any), and the new completion / failure / skip events since then. Fold
        them into ONE updated, concise summary and surface the behavioural
        patterns you can see — when and why the user tends to succeed or fail
        (e.g. "fails on late workdays", "succeeds when the cue is visual"). Keep
        it grounded strictly in the events provided; do not invent history.

        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "content":  string,    // the updated rolling summary, a few sentences
          "patterns": string[]   // short behavioural patterns; [] if none yet
        }
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->max(4000)->required(),
            'patterns' => $schema->array()->items($schema->string()->max(200))->max(12),
        ];
    }

    public function middleware(): array
    {
        return [GuardCoachUsage::class];
    }
}
