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
     * carries no schedule, and a reschedule carries no title. `kind` gates the
     * schedule half — `required_with` on the rest is what stops a half-stated
     * schedule reaching the writer.
     *
     * A payload with nothing in it is refused in withValidator() rather than
     * here: "at least one of these" is not a per-field rule.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:250'],
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
                $validator->errors()->add('title', 'Pass at least one field to change.');
            }
        });
    }
}
