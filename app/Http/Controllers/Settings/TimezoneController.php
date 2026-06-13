<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Captures the browser-reported IANA timezone once, so action schedules localise
 * correctly. The frontend PATCHes this on first authenticated load when the
 * user's timezone is still null.
 */
class TimezoneController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'timezone'],
        ]);

        $request->user()->update(['timezone' => $validated['timezone']]);

        return back();
    }
}
