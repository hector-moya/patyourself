<?php

namespace App\Http\Requests;

use App\Models\Intention;
use App\Services\Coach\Authoring\IntentionSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a manually-authored loop. Mirrors the LLM authoring contract
 * ({@see IntentionSchema}) so hand-entered and AI-authored loops share a shape,
 * minus the model-only fields (confidence, tags, seeded strategy).
 */
class StoreIntentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', 'string', Rule::in(IntentionSchema::TYPES)],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'cue' => ['required', 'string', 'max:2000'],
            'craving' => ['required', 'string', 'max:2000'],
            'response' => ['required', 'string', 'max:2000'],
            'reward' => ['required', 'string', 'max:2000'],
        ];
    }

    /** The statuses a user may set on their own loop. */
    public const STATUSES = [
        Intention::STATUS_ACTIVE,
        Intention::STATUS_PAUSED,
        Intention::STATUS_ARCHIVED,
        Intention::STATUS_COMPLETED,
    ];
}
