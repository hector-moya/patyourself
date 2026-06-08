<?php

namespace App\Services\Coach\Chat;

use App\Services\Coach\Authoring\IntentionSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The contract for the coach's chat envelope: a conversational reply plus an
 * optional Intention "card". The reply is required; the card is validated
 * separately by IntentionSchema so a bad card can be dropped without losing the
 * reply. Prompt and validation live together so they can't drift.
 */
final class ChatReplySchema
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'reply' => ['required', 'string', 'max:4000'],
            'intention' => ['nullable', 'array'],
        ];
    }

    /**
     * Validate the envelope (reply + presence of an optional card). The card's
     * own shape is validated downstream.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ChatException
     */
    public static function validate(array $payload): array
    {
        $validator = Validator::make($payload, self::rules());

        if ($validator->fails()) {
            throw ChatException::invalid($validator->errors()->all(), $payload);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    public static function contract(): string
    {
        $fields = IntentionSchema::fields();

        return <<<PROMPT
        Return ONE JSON object and nothing else — no prose, no Markdown fences —
        with exactly these fields:

        {
          "reply":     string,        // your conversational reply to the user
          "intention": object | null  // an authored habit loop to show as a card, or null
        }

        Include "intention" ONLY when the user is describing a habit to build or
        break and you have enough to author the loop; otherwise set it to null.
        When non-null, "intention" MUST be an object of exactly this shape:

        {$fields}
        PROMPT;
    }
}
