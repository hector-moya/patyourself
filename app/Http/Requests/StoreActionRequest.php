<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the IntentionPolicy
    }

    /**
     * Mirrors RescheduleActionRequest's shape so the add and edit forms speak
     * the same language, plus the title an existing action already has.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:clock,anchored'],
            'time' => ['nullable', 'required_if:kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'anchor' => ['nullable', 'required_if:kind,anchored', 'string', 'max:255'],
        ];
    }
}
