<?php

namespace App\Http\Requests\Training;

use App\Actions\Training\ReorderRoutine;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation for a routine reorder: `order` must be an array of
 * integers. Whether it actually names exactly the action's current rows is
 * business logic that depends on the database, not the payload's shape, so
 * it is checked in {@see ReorderRoutine}, not here.
 */
class ReorderRoutineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the ActionPolicy
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ];
    }
}
