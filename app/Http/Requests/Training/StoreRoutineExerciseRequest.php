<?php

namespace App\Http\Requests\Training;

use App\Models\Exercise;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adds one row to an action's routine — the gym workflow's config extension
 * site. Ownership of the action itself is enforced in the controller via
 * ActionPolicy; this only checks that the exercise named is one the user is
 * allowed to see.
 */
class StoreRoutineExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the ActionPolicy
    }

    /**
     * `target_reps` is a single integer, not a range — v1 says "10", not
     * "8-12". See ActionExercise's own doc block for why that is chosen
     * rather than overlooked.
     *
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
            'target_sets' => ['required', 'integer', 'min:1'],
            'target_reps' => ['required', 'integer', 'min:1'],
        ];
    }
}
