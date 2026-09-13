<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validating a reschedule over the JSON API.
 *
 * Deliberately separate from `App\Http\Requests\RescheduleActionRequest`, which
 * the web endpoint uses. That one widened to accept a title and a description
 * so the owner could rename an action from the loop screen, which meant `kind`
 * became optional there. This endpoint reschedules and nothing else, and
 * `RescheduleAction::handle()` takes a non-nullable `string $kind` — so sharing
 * the widened class turned a clean 422 into a TypeError for any payload
 * without a `kind`.
 *
 * Two endpoints, two contracts. Whether the API should also be able to rename
 * an action is a real question with its own answer; it is not this change.
 */
class RescheduleActionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:clock,anchored'],
            'time' => ['nullable', 'required_if:kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'anchor' => ['nullable', 'required_if:kind,anchored', 'string', 'max:255'],
        ];
    }
}
