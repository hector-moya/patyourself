<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationsUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email_reminders' => ['required', 'string', Rule::in(User::EMAIL_REMINDER_MODES)],
            'digest_time' => [
                Rule::requiredIf(fn (): bool => $this->input('email_reminders') === User::EMAIL_REMINDERS_DIGEST),
                'nullable',
                'date_format:H:i',
            ],
        ];
    }
}
