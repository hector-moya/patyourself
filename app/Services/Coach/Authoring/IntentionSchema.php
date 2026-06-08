<?php

namespace App\Services\Coach\Authoring;

use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Support\Facades\Validator;

/**
 * The contract for an LLM-authored Intention. This is the single source of
 * truth for the structured object the coach must emit: it produces the prompt
 * instructions the model reads, and validates the JSON the model returns.
 *
 * Keeping the prompt-facing description and the server-side validation rules in
 * one place means they can never drift apart — the model is told exactly what
 * is then enforced. AI authors the data; this class guards its shape.
 */
final class IntentionSchema
{
    /** Allowed loop types. */
    public const TYPES = [Intention::TYPE_BUILD, Intention::TYPE_BREAK];

    /** Allowed points in the behavioural chain a strategy can intervene on. */
    public const INTERVENTION_POINTS = [
        Strategy::POINT_CUE,
        Strategy::POINT_CRAVING,
        Strategy::POINT_RESPONSE,
        Strategy::POINT_REWARD,
    ];

    /**
     * Laravel validation rules for the decoded payload.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],

            // The cue -> craving -> response -> reward chain. All four are
            // required so the authored loop is complete enough to render.
            'cue' => ['required', 'string', 'max:2000'],
            'craving' => ['required', 'string', 'max:2000'],
            'response' => ['required', 'string', 'max:2000'],
            'reward' => ['required', 'string', 'max:2000'],

            'confidence' => ['nullable', 'numeric', 'between:0,1'],
            'tags' => ['nullable', 'array', 'max:8'],
            'tags.*' => ['string', 'max:50'],

            // Optional initial intervention. The versioned-strategy logic
            // (stack/restrategize) lives elsewhere; here we only seed v1.
            'strategy' => ['nullable', 'array'],
            'strategy.intervention_point' => [
                'required_with:strategy',
                'string',
                'in:'.implode(',', self::INTERVENTION_POINTS),
            ],
            'strategy.approach' => ['required_with:strategy', 'string', 'max:2000'],
            'strategy.rationale' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Validate a decoded payload, returning only the recognised keys.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws IntentionAuthoringException
     */
    public static function validate(array $payload): array
    {
        $validator = Validator::make($payload, self::rules());

        if ($validator->fails()) {
            throw IntentionAuthoringException::validationFailed(
                $validator->errors()->all(),
                $payload,
            );
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * The JSON output contract — the exact shape the model must return, and
     * nothing but that JSON. Composed into the system prompt by CoachPrompts;
     * lives here beside the validation rules so the two can't drift.
     */
    public static function contract(): string
    {
        $types = implode(' | ', self::TYPES);
        $points = implode(' | ', self::INTERVENTION_POINTS);

        return <<<PROMPT
        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "title":       string,  // short, concrete name for the loop
          "description": string,  // one or two sentences on the loop and why it matters
          "type":        "{$types}",  // "build" a good habit or "break" a bad one
          "cue":         string,  // the trigger that starts the loop
          "craving":     string,  // the underlying motivation / desired feeling
          "response":    string,  // the actual behaviour performed
          "reward":      string,  // the payoff that reinforces it
          "confidence":  number,  // 0..1, your confidence this loop fits the user
          "tags":        string[],// up to 8 short topical tags
          "strategy": {           // an initial intervention to try first
            "intervention_point": "{$points}",
            "approach":  string,  // the concrete tactic at that point in the chain
            "rationale": string   // why intervening there should help
          }
        }
        PROMPT;
    }
}
