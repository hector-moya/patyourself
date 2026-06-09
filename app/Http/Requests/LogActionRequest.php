<?php

namespace App\Http\Requests;

use App\Models\ActionLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a logged outcome. A failure must carry the user-stated reason —
 * that reason is what later drives a strategy to restrategize, so it is never
 * optional on failure.
 */
class LogActionRequest extends FormRequest
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
            'outcome' => ['required', 'string', Rule::in([
                ActionLog::OUTCOME_COMPLETED,
                ActionLog::OUTCOME_FAILED,
                ActionLog::OUTCOME_SKIPPED,
            ])],
            'reason' => [
                Rule::requiredIf(fn () => $this->input('outcome') === ActionLog::OUTCOME_FAILED),
                'nullable',
                'string',
                'max:2000',
            ],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
