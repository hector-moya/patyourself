<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'timezone' => $this->timezoneFrom($input),
        ]);
    }

    /**
     * The browser's own IANA zone, sent as a hidden field by the register form.
     *
     * Every schedule in the app is worked out from this, and until it was
     * captured here a new account ran silently on config('app.timezone') until
     * someone thought to visit the timezone settings screen.
     *
     * Deliberately NOT part of the validation rules above: registering must
     * never fail because a browser reported a zone this PHP build does not
     * recognise. Anything unrecognised is simply dropped, and the account falls
     * back to the app default exactly as it did before.
     *
     * `ALL_WITH_BC` rather than `timezone_identifiers_list()` because the
     * latter excludes backward aliases such as `US/Pacific` and
     * `Asia/Calcutta`, which browsers do still report.
     *
     * @param  array<string, string>  $input
     */
    private function timezoneFrom(array $input): ?string
    {
        $timezone = $input['timezone'] ?? null;

        if (! is_string($timezone) || $timezone === '') {
            return null;
        }

        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            ? $timezone
            : null;
    }
}
