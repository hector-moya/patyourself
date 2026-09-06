<?php

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     *
     * The account goes first and the session is only touched once that has
     * returned. The other order — sign out, then delete — leaves a refused
     * deletion (see {@see DeleteUser}) with the person signed out of an account
     * that is still there, which is the worst of both.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUser $delete): RedirectResponse
    {
        $user = $request->user();

        $delete->handle($user);

        // The row is gone; what is left is a signed-in session pointing at
        // nothing. Auth::logout() re-saves the user to cycle its remember token,
        // and save() on a deleted model is an INSERT — it would put the account
        // straight back. The token died with the row it belonged to, so say so
        // and logout has nothing to cycle.
        $user->setRememberToken(null);

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
