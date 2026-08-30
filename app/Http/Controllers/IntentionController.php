<?php

namespace App\Http\Controllers;

use App\Actions\CreateIntention;
use App\Actions\DeleteIntention;
use App\Actions\UpdateIntention;
use App\Http\Requests\StoreIntentionRequest;
use App\Http\Requests\UpdateIntentionRequest;
use App\Http\Resources\IntentionResource;
use App\Http\Resources\StrategyResource;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Services\Progress\LoopProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Inertia web side of loops. Renders the loops-list and loop-detail
 * screens, and funnels every write through the same shared Actions the JSON
 * API uses, so the two surfaces stay in lockstep. The screen *content* is
 * fleshed out in Tasks 19–20; this controller hands those pages their props.
 */
class IntentionController extends Controller
{
    /** Recent history by default — the whole thing is behind an explicit control. */
    private const HISTORY_PAGE = 30;

    /** A ceiling even on "show everything", so one screen cannot become unbounded. */
    private const HISTORY_MAX = 500;

    private const NOTE_LIMIT = 50;

    public function index(Request $request): Response
    {
        $intentions = $request->user()->intentions()
            ->with('activeStrategy')
            ->latest()
            ->get()
            // Surface the loops the user is actively working first; the rest
            // (paused / completed / archived) settle below, newest-first within.
            ->sortBy(fn (Intention $intention): int => $intention->status === Intention::STATUS_ACTIVE ? 0 : 1)
            ->values();

        return Inertia::render('loops/index', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
        ]);
    }

    public function show(Request $request, Intention $intention, LoopProgress $progress): Response
    {
        Gate::authorize('view', $intention);

        $intention->load(['activeStrategy', 'activeAction', 'latestSummary', 'actionLogs']);
        $strategies = $intention->strategies()->withCount('actionLogs')->orderedByVersion()->get();
        $showingAll = $request->query('history') === 'all';
        // Dates are localised here so the day an occasion belongs to is the
        // user's day, not the browser's.
        $timezone = $request->user()->timezone ?? (string) config('app.timezone');

        $reflection = $intention->latestSummary;

        return Inertia::render('loops/show', [
            'intention' => (new IntentionResource($intention))->resolve(),
            'strategies' => StrategyResource::collection($strategies)->resolve(),
            // The current experiment's own record, kept separate from the loop's
            // lifetime. Null between experiments, which is a good state — the
            // screen says so plainly rather than rendering a hollow shape.
            'current_version' => $progress->forCurrentVersion($intention),
            // One entry per version, oldest first. Logs attribute through
            // actions.strategy_id, so this is what says whether changing the
            // strategy actually changed anything.
            'experiments' => $progress->experimentsFor($intention),
            // The window and the count come from the record, not from Claude —
            // dropping them would leave a claim with no provenance.
            'reflection' => $reflection === null ? null : [
                'content' => $reflection->content,
                'window_start' => $reflection->window_start?->toIso8601String(),
                'window_end' => $reflection->window_end?->toIso8601String(),
                'events_count' => $reflection->events_count,
            ],
            'outcomes' => $this->outcomeHistory($intention, $showingAll, $timezone),
            'outcomes_total' => $intention->actionLogs()->count(),
            'showing_all_history' => $showingAll,
            'notes' => $intention->notes()->limit(self::NOTE_LIMIT)->get()
                ->map(fn (Note $note): array => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'noted_at' => $note->noted_at->timezone($timezone)->toIso8601String(),
                ])->values()->all(),
            // Live actions for the action layer. The raw scheduling fields
            // are sent as-is, mirroring `active_action` on IntentionResource,
            // so the client formats the cadence with the one function that
            // already handles every null combination — see cadenceLabel in
            // resources/js/patyourself/loops/cadence.ts. A schedule_kind of
            // "clock" with a recurrence but no upcoming occurrence must read
            // as "daily", not "daily at " with nothing after it.
            'actions' => $intention->actions()
                ->where('status', '!=', Action::STATUS_ARCHIVED)
                ->get()
                ->map(fn (Action $action): array => [
                    'id' => $action->id,
                    'title' => $action->title,
                    'recurrence' => $action->recurrence,
                    'schedule_kind' => $action->metadata['schedule_kind'] ?? null,
                    'anchor' => $action->metadata['anchor'] ?? null,
                    'next_occurrence_at' => $action->nextOccurrenceAt()?->timezone($timezone)->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    /**
     * The loop's outcomes, newest occasion first.
     *
     * Dated by the occasion rather than by `logged_at`, which is what makes a
     * caught-up entry sit where it belongs in the history rather than bunching
     * with everything else typed in the same check-in. A log written before
     * occurrences existed falls back to when it was typed — the only date the
     * old model recorded.
     *
     * @return list<array<string, mixed>>
     */
    private function outcomeHistory(Intention $intention, bool $showingAll, string $timezone): array
    {
        $logs = ActionLog::query()
            ->with(['occurrence', 'action.strategy'])
            ->whereHas('action', fn (Builder $query) => $query->where('intention_id', $intention->id))
            ->get()
            ->sortByDesc(fn (ActionLog $log): string => (
                $log->occurrence?->scheduled_for ?? $log->logged_at
            )->toDateTimeString());

        return $logs
            ->take($showingAll ? self::HISTORY_MAX : self::HISTORY_PAGE)
            ->map(fn (ActionLog $log): array => [
                'id' => $log->id,
                'occurred_at' => ($log->occurrence?->scheduled_for ?? $log->logged_at)
                    ->timezone($timezone)->toIso8601String(),
                'logged_at' => $log->logged_at->timezone($timezone)->toIso8601String(),
                'action_id' => $log->action_id,
                'action_title' => $log->action->title,
                'outcome' => $log->outcome,
                // Verbatim, exactly as the user said it.
                'reason' => $log->reason,
                'context' => $log->context,
                'context_fields' => $log->context_fields,
                'strategy_version' => $log->action->strategy?->version,
            ])
            ->values()
            ->all();
    }

    public function store(StoreIntentionRequest $request, CreateIntention $create): RedirectResponse
    {
        $create->handle($request->user(), $request->validated());

        return back();
    }

    public function update(UpdateIntentionRequest $request, Intention $intention, UpdateIntention $update): RedirectResponse
    {
        Gate::authorize('update', $intention);

        $update->handle($intention, $request->validated());

        return back();
    }

    public function destroy(Intention $intention, DeleteIntention $delete): RedirectResponse
    {
        Gate::authorize('delete', $intention);

        $delete->handle($intention);

        return to_route('loops.index');
    }
}
