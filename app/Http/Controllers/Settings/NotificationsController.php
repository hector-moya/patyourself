<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationsUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the user wants habit cues delivered by email. The in-app inbox is not
 * configurable here — it always receives every cue.
 */
class NotificationsController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'emailReminders' => $user->email_reminders,
            'digestTime' => $user->digest_time,
            'modes' => User::EMAIL_REMINDER_MODES,
        ]);
    }

    public function update(NotificationsUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reminder settings updated.')]);

        return to_route('notifications.edit');
    }
}
