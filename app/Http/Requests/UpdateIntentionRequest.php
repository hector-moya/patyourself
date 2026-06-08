<?php

namespace App\Http\Requests;

use App\Services\Coach\Authoring\IntentionSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial edit to a loop. Every field is `sometimes` so PATCH-style
 * updates can touch a single field, but anything present must still be valid.
 */
class UpdateIntentionRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'type' => ['sometimes', 'required', 'string', Rule::in(IntentionSchema::TYPES)],
            'status' => ['sometimes', 'required', 'string', Rule::in(StoreIntentionRequest::STATUSES)],
            'cue' => ['sometimes', 'required', 'string', 'max:2000'],
            'craving' => ['sometimes', 'required', 'string', 'max:2000'],
            'response' => ['sometimes', 'required', 'string', 'max:2000'],
            'reward' => ['sometimes', 'required', 'string', 'max:2000'],
        ];
    }
}
