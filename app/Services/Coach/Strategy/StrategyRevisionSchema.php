<?php

namespace App\Services\Coach\Strategy;

use App\Services\Coach\Authoring\IntentionSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The contract for an LLM-authored *next strategy version*. As with
 * IntentionSchema, the prompt the model reads and the rules the server enforces
 * live together so they cannot drift. Drives both transitions:
 * stack-on-success and restrategize-on-failure.
 */
final class StrategyRevisionSchema
{
    public const MODE_STACK = 'stack';

    public const MODE_RESTRATEGIZE = 'restrategize';

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'intervention_point' => [
                'required',
                'string',
                'in:'.implode(',', IntentionSchema::INTERVENTION_POINTS),
            ],
            'approach' => ['required', 'string', 'max:2000'],
            'rationale' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws StrategyTransitionException
     */
    public static function validate(array $payload): array
    {
        $validator = Validator::make($payload, self::rules());

        if ($validator->fails()) {
            throw StrategyTransitionException::invalidRevision(
                $validator->errors()->all(),
                $payload,
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * System-prompt instructions for the given transition mode.
     */
    public static function instructions(string $mode): string
    {
        $points = implode(' | ', IntentionSchema::INTERVENTION_POINTS);

        $intent = $mode === self::MODE_STACK
            ? <<<'TXT'
            The current strategy WORKED. Stack toward a harder goal: raise the
            challenge while keeping the loop achievable. You may keep the same
            intervention_point (a tougher version of the same tactic) or advance
            it if that makes the next step land better.
            TXT
            : <<<'TXT'
            The current strategy FAILED. Read the user's stated reason and move
            the intervention_point UP (earlier) or DOWN (later) the
            cue -> craving -> response -> reward chain to a point that addresses
            why it failed — e.g. if the response was too hard, intervene earlier
            on the cue; if motivation was missing, intervene on the craving.
            TXT;

        return <<<PROMPT
        You are PatYourSelf's habit coach revising the strategy for an existing
        habit loop. {$intent}

        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "intervention_point": "{$points}",
          "approach":  string,  // the concrete tactic at that point in the chain
          "rationale": string   // why this revision should help, given what happened
        }

        Keep the revision grounded in the loop and the outcome described; do not
        invent unrelated specifics.
        PROMPT;
    }
}
