<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

/**
 * The one definition of "what is due today" for a user: pending actions on
 * active loops that are either cue-anchored or scheduled by the end of the
 * user's local day.
 *
 * Shared so the daily digest email and the today-actions MCP tool can never
 * disagree about what the user owes today.
 */
class TodaysActions
{
    /**
     * @return Collection<int, Action>
     */
    public function for(User $user): Collection
    {
        $timezone = $user->timezone ?? config('app.timezone');
        $endOfToday = Date::now($timezone)->endOfDay()->utc();

        return Action::query()
            ->pending()
            ->whereHas('intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->where(fn (Builder $query) => $query
                ->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', $endOfToday))
            ->with('intention:id,title')
            ->orderBy('scheduled_for')
            ->get();
    }
}
