<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RescheduleActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the ActionPolicy
    }

    /**
     * Every field is optional because this endpoint amends an action: a rename
     * carries no schedule, and a reschedule carries no title. `kind` marks
     * whether a schedule was submitted at all; the controller's `kind !== null`
     * check is what stops a half-stated schedule reaching the writer — these
     * rules only shape a schedule that is present, they do not require one.
     *
     * A payload with nothing in it is refused in withValidator() rather than
     * here: "at least one of these" is not a per-field rule.
     *
     * `time` carries no `sometimes`, so a `time` submitted without a `kind`
     * still passes shape validation (the regex applies whenever the field is
     * present) but is never read: the controller only reaches `time` once
     * `kind` is set, so a schedule-shaped field with no `kind` is silently
     * inert rather than rejected. Left as-is because the edit form this
     * endpoint serves always submits `kind` alongside `time`.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => ['sometimes', 'in:clock,anchored'],
            'time' => ['nullable', 'required_if:kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'anchor' => ['nullable', 'required_if:kind,anchored', 'string', 'max:255'],
        ];
    }

    /**
     * Refuses a payload that would change nothing. Without this the endpoint
     * answers a redirect to a request that did nothing, which reads as success.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $changes = array_intersect_key(
                $this->all(),
                array_flip(['title', 'description', 'kind']),
            );

            if ($changes === []) {
                // Anchored to `title` because it is the one field the edit
                // form (Task 4) always renders, so it is the key whose error
                // actually surfaces next to a control on the page.
                $validator->errors()->add('title', 'Pass at least one field to change.');
            }
        });
    }
}
