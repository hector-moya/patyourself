<?php

namespace App\Http\Requests;

use App\Models\Strategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ownership is enforced in the controller via the IntentionPolicy
    }

    /**
     * The web twin of StartExperimentTool's rules.
     *
     * AuthoredStrategy carries no guard of its own — the only validation of
     * `intervention_point` and a non-empty `approach` happens at whichever
     * boundary the write arrives through. Both boundaries build their rules
     * from the same model constants so a new point or reason moves them together.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'intervention_point' => ['required', 'string', Rule::in(Strategy::INTERVENTION_POINTS)],
            'approach' => ['required', 'string', 'min:1', 'max:2000'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'supersedes_reason' => ['nullable', 'string', 'max:2000'],
            'change_reason' => ['nullable', 'string', Rule::in(Strategy::CHANGE_REASONS)],
            // Null is open-ended, and open-ended is a valid experiment.
            'review_after_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            // Passing an action re-proposes the cadence; omitting it inherits the
            // prior one. That is the least guessable part of StartExperiment's
            // API, so the form asks rather than defaulting.
            'cadence' => ['required', 'in:keep,change'],
            'action_title' => ['nullable', 'required_if:cadence,change', 'string', 'max:255'],
            'action_kind' => ['nullable', 'required_if:cadence,change', 'in:clock,anchored'],
            'action_time' => ['nullable', 'required_if:action_kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'action_recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'action_anchor' => ['nullable', 'required_if:action_kind,anchored', 'string', 'max:255'],
        ];
    }
}
