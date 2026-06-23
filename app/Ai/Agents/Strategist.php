<?php

namespace App\Ai\Agents;

use App\Ai\Concerns\MetersUsageToUser;
use App\Ai\Middleware\GuardCoachUsage;
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
 * Authors the *next* version of a strategy. Given the current strategy loop
 * context and the outcome (success → stack; failure → restrategize), it returns
 * a structured revision the ReviseStrategy action persists as a new version.
 *
 * Two modes, both append-only (never in-place edits):
 *  - stack: the strategy SUCCEEDED — raise the challenge slightly while keeping
 *    the loop achievable. May stay at the same intervention_point or advance it.
 *  - restrategize: the strategy FAILED — read the user-stated reason and shift
 *    the intervention_point earlier or later in the chain to address why.
 *
 * The user prompt must declare which mode applies (see ReviseStrategy).
 */
#[Provider(Lab::Anthropic)]
#[Model('claude-haiku-4-5')]
#[Temperature(0.5)]
#[MaxTokens(2048)]
class Strategist implements Agent, HasMiddleware, HasStructuredOutput
{
    use MetersUsageToUser, Promptable;

    /**
     * Combined identifier for both modes — they share the same charter and
     * schema contract; only the framing paragraph in the user prompt differs.
     * Old per-mode versions were 'strategy-stack@1' and 'strategy-restrategize@1';
     * this agent unifies them under a single constant.
     */
    public const PROMPT_VERSION = 'strategy-revision@1';

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

        The user prompt declares which mode applies:

        STACK mode — the current strategy SUCCEEDED. Stack toward a harder goal —
        raise the challenge while keeping the loop achievable. You may keep the same
        intervention_point (a tougher version of the same tactic) or advance it
        if that makes the next step land better.

        RESTRATEGIZE mode — the current strategy FAILED. Read the user's stated
        reason and move the intervention_point UP (earlier) or DOWN (later) the
        cue -> craving -> response -> reward chain to a point that addresses why
        it failed — e.g. if the response was too hard, intervene earlier on the
        cue; if motivation was missing, intervene on the craving.

        If (and only if) the failure was about timing — the user tried at the
        wrong moment — propose a new action.schedule. Otherwise omit action and
        the existing cadence is kept.

        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "intervention_point": "cue | craving | response | reward",
          "approach":  string,  // the concrete tactic at that point in the chain
          "rationale": string,  // why this revision should help, given what happened
          "action": {           // OPTIONAL — only when the cadence should change
            "title":       string,
            "schedule": { "kind": "clock | anchored", "time": "HH:MM", "recurrence": "once | daily | weekdays | weekly", "anchor": string }
          }
        }
        TXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intervention_point' => $schema->string()
                ->enum([Strategy::POINT_CUE, Strategy::POINT_CRAVING, Strategy::POINT_RESPONSE, Strategy::POINT_REWARD])
                ->required(),
            'approach' => $schema->string()->max(2000)->required(),
            'rationale' => $schema->string()->max(2000),
            'action' => $schema->object(fn ($schema) => [
                'title' => $schema->string()->max(255),
                'description' => $schema->string()->max(2000),
                'schedule' => $schema->object(fn ($schema) => [
                    'kind' => $schema->string()->enum(['clock', 'anchored']),
                    'time' => $schema->string(),
                    'recurrence' => $schema->string()->enum(['once', 'daily', 'weekdays', 'weekly']),
                    'anchor' => $schema->string()->max(255),
                ]),
            ]),
        ];
    }

    public function middleware(): array
    {
        return [GuardCoachUsage::class];
    }
}
