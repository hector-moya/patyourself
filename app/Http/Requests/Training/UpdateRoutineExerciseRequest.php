<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validating an edit to a routine row's targets.
 *
 * The rules mirror {@see StoreRoutineExerciseRequest}'s for the same two
 * fields, so a target that could be added can be edited to and back. There is
 * no `exercise_id` here: which exercise a row is for is not editable — that is
 * a different exercise, which is a remove and an add.
 *
 * Authorization is the controller's `Gate::authorize('update', $action)`, as it
 * is for every other write on this surface.
 */
class UpdateRoutineExerciseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the ActionPolicy
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'target_sets' => ['required', 'integer', 'min:1'],
            'target_reps' => ['required', 'integer', 'min:1'],
        ];
    }
}
