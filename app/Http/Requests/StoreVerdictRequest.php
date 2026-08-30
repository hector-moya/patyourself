<?php

namespace App\Http\Requests;

use App\Models\Strategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVerdictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the StrategyPolicy
    }

    /**
     * Rules are built from Strategy::VERDICTS rather than a literal list, so a
     * new verdict reaches this boundary and the MCP tool's boundary together.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'verdict' => ['required', 'string', Rule::in(Strategy::VERDICTS)],
            // A failed experiment has to say what did not hold. The note is what
            // the next experiment gets written from, exactly as a failure reason is.
            'note' => ['nullable', 'string', 'max:2000', Rule::requiredIf(
                fn (): bool => $this->input('verdict') === Strategy::VERDICT_FAILED,
            )],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'note.required' => 'Say what the strategy did not do. This is what the next experiment is written from.',
        ];
    }
}
