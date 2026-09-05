<?php

namespace App\Http\Requests;

use App\Models\Intention;
use App\Services\Workflows\WorkflowRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a manually-authored loop.
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
            'type' => ['required', 'string', Rule::in(Intention::TYPES)],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'cue' => ['required', 'string', 'max:2000'],
            'craving' => ['required', 'string', 'max:2000'],
            'response' => ['required', 'string', 'max:2000'],
            'reward' => ['required', 'string', 'max:2000'],
            // Chosen from the registry, never typed. A tag was the earlier
            // answer and it fails silently on `Gym`, `gimnasio` or a trailing
            // space, with nothing on screen to say why.
            'workflow' => ['sometimes', 'nullable', 'string', Rule::in(app(WorkflowRegistry::class)->names())],
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
