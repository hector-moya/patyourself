<?php

namespace App\Http\Requests\Training;

use App\Actions\Training\RecordSet;
use App\Models\Exercise;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates one set being recorded against an occasion. Ownership of the
 * occasion itself is enforced in the controller via
 * `Gate::authorize('log', $occurrence)`; this only checks that the exercise
 * named is one the user is allowed to see, and that the set itself means
 * something.
 *
 * `reps` is required and `weight` is nullable — the spec's Error handling:
 * "a set with reps but no weight is valid (body weight); a set with neither
 * is not recorded" reduces to that one rule once weight is nullable, since a
 * payload naming neither is refused here for lacking `reps` before it ever
 * reaches {@see RecordSet}.
 */
class StorePerformedSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership of the occasion is enforced in the controller
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'exercise_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! Exercise::query()->availableTo($this->user())->whereKey($value)->exists()) {
                        $fail('That exercise is not in your catalogue.');
                    }
                },
            ],
            'reps' => ['required', 'integer', 'min:1'],
            'weight' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
