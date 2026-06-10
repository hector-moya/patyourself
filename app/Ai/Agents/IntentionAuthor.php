<?php

namespace App\Ai\Agents;

use App\Ai\Middleware\GuardCoachUsage;
use App\Models\Intention;
use App\Models\Strategy;
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
 * Authors a complete habit loop (Intention + initial Strategy) from a user-stated
 * goal. Never touches the DB — CreateLoop persists via AuthorIntention.
 *
 * Ported system prompt from CoachPrompts::intentionAuthoring() (charter +
 * authoring framing + JSON contract). Runs the GuardCoachUsage middleware so
 * every LLM call is metered into the coach_usages ledger.
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-sonnet-4-6')]
#[Temperature(0.7)]
#[MaxTokens(2048)]
class IntentionAuthor implements Agent, HasMiddleware, HasStructuredOutput
{
    use Promptable;

    public const PROMPT_VERSION = 'intention-authoring@1';

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

        Task: the user describes a habit they want to build or break. Author a
        single structured Intention — a habit loop modelled on the cue -> craving
        -> response -> reward chain — plus an initial strategy to try first.

        For a "break" loop, cue/craving/response/reward describe the UNWANTED loop
        as it happens today, and the strategy is how to disrupt it. Choose the
        single intervention_point most likely to move the behaviour.

        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "title":       string,  // short, concrete name for the loop
          "description": string,  // one or two sentences on the loop and why it matters
          "type":        "build | break",  // "build" a good habit or "break" a bad one
          "cue":         string,  // the trigger that starts the loop
          "craving":     string,  // the underlying motivation / desired feeling
          "response":    string,  // the actual behaviour performed
          "reward":      string,  // the payoff that reinforces it
          "confidence":  number,  // 0..1, your confidence this loop fits the user
          "tags":        string[],// up to 8 short topical tags
          "strategy": {           // an initial intervention to try first
            "intervention_point": "cue | craving | response | reward",
            "approach":  string,  // the concrete tactic at that point in the chain
            "rationale": string   // why intervening there should help
          }
        }
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(255)->required(),
            'description' => $schema->string()->max(2000),
            'type' => $schema->string()->enum([Intention::TYPE_BUILD, Intention::TYPE_BREAK])->required(),
            'cue' => $schema->string()->max(2000)->required(),
            'craving' => $schema->string()->max(2000)->required(),
            'response' => $schema->string()->max(2000)->required(),
            'reward' => $schema->string()->max(2000)->required(),
            'confidence' => $schema->number()->min(0)->max(1),
            'strategy' => $schema->object(fn ($schema) => [
                'intervention_point' => $schema->string()
                    ->enum([Strategy::POINT_CUE, Strategy::POINT_CRAVING, Strategy::POINT_RESPONSE, Strategy::POINT_REWARD])
                    ->required(),
                'approach' => $schema->string()->max(2000)->required(),
                'rationale' => $schema->string()->max(2000),
            ]),
        ];
    }

    public function middleware(): array
    {
        return [GuardCoachUsage::class];
    }
}
