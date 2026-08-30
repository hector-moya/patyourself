<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Action;
use App\Models\Intention;
use App\Services\Scheduling\ReanchorsSeries;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets the user see and correct the IANA timezone every schedule is worked
 * out from. The screen suggests the browser-reported zone
 * (`Intl.DateTimeFormat().resolvedOptions().timeZone`, read client-side) when
 * it differs from what is stored, but nothing submits on its own — the user
 * has to press Save.
 */
class TimezoneController extends Controller
{
    public function __construct(private readonly ReanchorsSeries $reanchor) {}

    public function edit(Request $request): Response
    {
        $current = $request->user()->timezone ?? (string) config('app.timezone');

        return Inertia::render('settings/timezone', [
            'timezone' => $current,
            'timezones' => $this->selectableTimezones($current),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previousTimezone = $user->timezone ?? (string) config('app.timezone');

        $validated = $request->validate([
            'timezone' => ['required', Rule::in($this->selectableTimezones($previousTimezone))],
        ]);

        $timezone = $validated['timezone'];

        $user->update(['timezone' => $timezone]);

        // A no-op save — e.g. pressing Save without touching the control —
        // has nothing to move.
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
            $previousTimezone,
            $timezone,
        );

        return back();
    }

    /**
     * `timezone_identifiers_list()` returns only the 419 canonical IANA
     * zones — it excludes backward-compat aliases like `US/Pacific` or
     * `Asia/Calcutta`. A user whose stored zone is such an alias would
     * otherwise see a <select> with no matching <option>: the browser falls
     * back to displaying (and, on Save, submitting) whichever option is
     * first in the list, silently rewriting their zone to something they
     * never chose. Pushing the current zone into the list — and validating
     * against this same widened set, not the bare list — keeps it
     * selectable and keeps a no-op save a genuine no-op, without accepting
     * arbitrary unknown identifiers.
     *
     * @return Collection<int, string>
     */
    private function selectableTimezones(string $current): Collection
    {
        return collect(timezone_identifiers_list())->push($current)->unique()->sort()->values();
    }
}
