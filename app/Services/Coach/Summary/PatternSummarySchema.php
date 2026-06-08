<?php

namespace App\Services\Coach\Summary;

use Illuminate\Support\Facades\Validator;

/**
 * The contract for an LLM-authored rolling summary. As with the other coach
 * schemas, the prompt the model reads and the rules the server enforces live
 * together so they cannot drift.
 *
 * Pattern detection here is deliberately ML-free: the structured archive
 * (action logs) plus a folded text summary is enough. The model distils the
 * loop's history into a running summary and surfaces behavioural patterns.
 */
final class PatternSummarySchema
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:4000'],
            'patterns' => ['nullable', 'array', 'max:12'],
            'patterns.*' => ['string', 'max:200'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SummaryException
     */
    public static function validate(array $payload): array
    {
        $validator = Validator::make($payload, self::rules());

        if ($validator->fails()) {
            throw SummaryException::invalid($validator->errors()->all(), $payload);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * The JSON output contract for a rolling summary. Composed into the system
     * prompt by CoachPrompts; lives beside the validation rules so the two
     * can't drift.
     */
    public static function contract(): string
    {
        return <<<'PROMPT'
        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "content":  string,    // the updated rolling summary, a few sentences
          "patterns": string[]   // short behavioural patterns; [] if none yet
        }
        PROMPT;
    }
}
