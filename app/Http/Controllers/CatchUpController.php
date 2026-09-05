<?php

namespace App\Http\Controllers;

use App\Models\Intention;
use App\Models\Occurrence;
use App\Services\Scheduling\MaterialiseOccurrences;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app catch-up list: occasions that have passed with no outcome yet.
 *
 * The same read `pending-outcomes` performs for the coach, rendered. Recent by
 * default so a long gap does not turn the screen into an audit, with the whole
 * backlog behind an explicit control — nothing expires, and nothing here is
 * overdue. There is deliberately no count anywhere on this screen's entry
 * point: a backlog is not debt.
 */
class CatchUpController extends Controller
{
    /** A check-in opens on the recent window, not the whole backlog. */
    private const DEFAULT_WINDOW_DAYS = 14;

    private const LIMIT = 100;

    public function index(Request $request, MaterialiseOccurrences $materialise): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? (string) config('app.timezone');
        $showingAll = $request->query('since') === 'all';

        // Lazy materialisation: a read is the only thing that creates
        // occasions, so this is where one never logged first appears.
        $materialise->forUser($user);

        $since = $showingAll
            ? null
            : Date::now()->subDays(self::DEFAULT_WINDOW_DAYS);

        $occurrences = Occurrence::query()
            ->unlogged()
            ->with('action.intention:id,title,workflow')
            ->where('scheduled_for', '<=', Date::now())
            ->when($since !== null, fn (Builder $query) => $query->where('scheduled_for', '>=', $since))
            ->whereHas('action.intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->orderByDesc('scheduled_for')
            ->limit(self::LIMIT)
            ->get();

        return Inertia::render('catch-up', [
            'occurrences' => $occurrences->map(fn (Occurrence $occurrence): array => [
                'id' => $occurrence->id,
                'loop_id' => $occurrence->action->intention_id,
                'loop_title' => $occurrence->action->intention->title,
                'workflow' => $occurrence->action->intention->workflow,
                'action_id' => $occurrence->action_id,
                'action_title' => $occurrence->action->title,
                'scheduled_for' => $occurrence->scheduled_for->timezone($timezone)->toIso8601String(),
            ])->values()->all(),
            'showing_all' => $showingAll,
        ]);
    }
}
