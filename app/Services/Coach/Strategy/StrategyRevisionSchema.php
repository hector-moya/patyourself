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
     * The JSON output contract for a revised strategy version. Composed into the
     * system prompt by CoachPrompts; lives beside the validation rules so the
     * two can't drift.
     */
    public static function contract(): string
    {
        $points = implode(' | ', IntentionSchema::INTERVENTION_POINTS);

        return <<<PROMPT
        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "intervention_point": "{$points}",
          "approach":  string,  // the concrete tactic at that point in the chain
          "rationale": string   // why this revision should help, given what happened
        }
        PROMPT;
    }
}
