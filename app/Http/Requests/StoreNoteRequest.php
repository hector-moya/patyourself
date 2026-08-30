<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the IntentionPolicy
    }

    /**
     * `body` is validated for presence but never transformed. The stored value
     * is the raw input, spacing and casing included.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Guard against a whitespace-only note without altering what gets stored:
        // this only affects the value the `min:1` rule sees.
        if (is_string($this->input('body')) && trim($this->input('body')) === '') {
            $this->merge(['body' => '']);
        }
    }
}
