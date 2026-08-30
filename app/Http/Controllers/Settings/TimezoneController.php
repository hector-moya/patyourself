<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Intention;
use App\Services\Scheduling\ReanchorsSeries;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Captures the browser-reported IANA timezone, so action schedules localise
 * correctly. The frontend PATCHes this on first authenticated load when the
 * user's timezone is still null, and this screen lets it be seen and
 * corrected afterward.
 */
class TimezoneController extends Controller
{
    public function __construct(private readonly ReanchorsSeries $reanchor) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('settings/timezone', [
            'timezone' => $request->user()->timezone ?? (string) config('app.timezone'),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['required', 'timezone', Rule::in(timezone_identifiers_list())],
        ]);

        $timezone = $validated['timezone'];
        $user = $request->user();
        $previousTimezone = $user->timezone;

        $user->update(['timezone' => $timezone]);

        // A no-op save — including the automatic first-load PATCH re-sending
        // an already-captured zone — has nothing to move.
        if ($previousTimezone === $timezone) {
            return back();
        }

        $now = CarbonImmutable::now();

        // Occurrences store absolute instants, so without this a user who moves
        // keeps being cued at their old local time — silently, forever. Only
        // genuinely stale actions are touched — the same guard
        // UpdateIntention::reanchorStaleActions() applies — a future-dated one
        // is left as the user scheduled it.
        $this->reanchor->forActions(
            $user->intentions()
                ->with(['actions' => fn ($query) => $query
                    ->whereNotNull('series_started_at')
                    ->where('series_started_at', '<=', $now)])
                ->get()
                ->flatMap(fn (Intention $loop) => $loop->actions)
                ->where('status', '!=', Action::STATUS_ARCHIVED),
            $previousTimezone ?? (string) config('app.timezone'),
            $timezone,
        );

        return back();
    }
}
