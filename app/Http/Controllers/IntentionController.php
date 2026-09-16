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
use App\Models\ActionExercise;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use App\Services\Workflows\WorkflowDefinition;
use App\Services\Workflows\WorkflowRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public function index(Request $request): Response
    {
        // Only a known status filters; anything else is ignored rather than
        // erroring, so a hand-edited URL still shows the user their loops.
        $status = in_array($request->query('status'), Intention::STATUSES, true)
            ? (string) $request->query('status')
            : null;

        // Only a genuine string is a search term; a hand-edited `?q[]=x`
        // hands `query()` an array, and `(string) $array` raises a warning
        // that Laravel's error handler turns into a 500. `status` above and
        // `ExportController`'s `format` both tolerate garbage the same way.
        $rawQuery = $request->query('q', '');
        $term = is_string($rawQuery) ? trim($rawQuery) : '';
        $search = $term === '' ? null : $term;

        $intentions = $request->user()->intentions()
            ->with('activeStrategy')
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== null, fn (Builder $query) => $query->where(
                fn (Builder $inner) => $this->matchTitleOrChain($inner, $search),
            ))
            ->latest()
            ->get()
            // Surface the loops the user is actively working first; the rest
            // (paused / completed / archived) settle below, newest-first within.
            ->sortBy(fn (Intention $intention): int => $intention->status === Intention::STATUS_ACTIVE ? 0 : 1)
            ->values();

        return Inertia::render('loops/index', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
            'filters' => ['status' => $status, 'q' => $search],
            'records' => $this->recordCounts($intentions),
        ]);
    }

    /**
     * How much each loop's record adds up to, for the card's link into it.
     *
     * Sent as its own prop keyed by loop id rather than folded into
     * IntentionResource: that resource also answers `show` and the API, and a
     * count of outcomes is not part of what an intention *is*.
     *
     * One aggregate for the whole list, not a relation read per card — this
     * list renders every loop the user has.
     *
     * `decided` excludes skips, the same rule every rate in the app follows: an
     * occasion that never happened decided nothing.
     *
     * @param  Collection<int, Intention>  $intentions
     * @return array<int, array{held: int, decided: int}>
     */
    private function recordCounts(Collection $intentions): array
    {
        if ($intentions->isEmpty()) {
            return [];
        }

        return ActionLog::query()
            ->join('actions', 'actions.id', '=', 'action_logs.action_id')
            ->whereIn('actions.intention_id', $intentions->pluck('id'))
            ->whereIn('action_logs.outcome', [
                ActionLog::OUTCOME_COMPLETED,
                ActionLog::OUTCOME_FAILED,
            ])
            ->get(['action_logs.outcome', 'actions.intention_id'])
            ->groupBy('intention_id')
            ->map(fn (Collection $logs): array => [
                'held' => $logs->where('outcome', ActionLog::OUTCOME_COMPLETED)->count(),
                'decided' => $logs->count(),
            ])
            ->all();
    }

    /**
     * The cue is often what you remember, so search covers the whole chain and
     * not just the title.
     *
     * The term is bound, never interpolated. `%`, `_` and `\` are escaped so a
     * literal percent in a title is a percent rather than a wildcard — the
     * column list is a hardcoded allowlist, which is the only reason it is safe
     * to interpolate the column name into the raw fragment.
     *
     * The escape character is bound as its own parameter rather than embedded
     * as a quoted literal in the raw SQL text. `ESCAPE '\\'` (a PHP
     * double-quoted string collapses `\\` to one backslash) sends MySQL the
     * literal `ESCAPE '\'` — under MySQL's default sql_mode a backslash is
     * itself a string-escape character, so `\'` is consumed as an escaped
     * quote and the literal never closes, turning every `?q=` search into a
     * syntax error. SQLite has no such escaping and lets it through, which is
     * why the test suite (SQLite-backed) could not catch it. Binding `?` is
     * portable across both drivers and removes the fragile construct outright.
     */
    private function matchTitleOrChain(Builder $query, string $term): void
    {
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);

        foreach (['title', 'cue', 'craving', 'response', 'reward'] as $column) {
            $query->orWhereRaw("{$column} LIKE ? ESCAPE ?", ['%'.$escaped.'%', '\\']);
        }
    }

    public function show(Request $request, Intention $intention, LoopProgress $progress): Response
    {
        Gate::authorize('view', $intention);

        $intention->load(['activeStrategy', 'activeAction', 'latestSummary', 'actionLogs']);
        // `successor` is eager-loaded because dayOfExperiment() reads it to cap
        // a superseded version's run — without this the ladder costs one extra
        // query per version rendered.
        $strategies = $intention->strategies()
            ->with('successor')
            ->withCount('actionLogs')
            ->orderedByVersion()
            ->get();

        // The action layer localises its schedule, so the cadence a user reads
        // is in their own day rather than the browser's.
        $timezone = $request->user()->timezone ?? (string) config('app.timezone');

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
            // How many occasions the record holds, to label the link across to
            // it. The outcomes themselves, the notes and the reflection moved
            // to LoopRecordController — this page states what the loop is, and
            // sending the same facts from two controllers is how two screens
            // start disagreeing.
            'outcomes_total' => $intention->actionLogs()->count(),
            // Live actions for the action layer. The raw scheduling fields
            // are sent as-is, mirroring `active_action` on IntentionResource,
            // so the client formats the cadence with the one function that
            // already handles every null combination — see cadenceLabel in
            // resources/js/patyourself/loops/cadence.ts. A schedule_kind of
            // "clock" with a recurrence but no upcoming occurrence must read
            // as "daily", not "daily at " with nothing after it.
            'actions' => $this->actionLayer($intention, $timezone),
            // What a loop may be set to record, straight from the registry.
            // The client draws the control from this rather than holding its
            // own copy of the list: the server is what decides which names are
            // acceptable (see UpdateIntentionRequest), and a second list in
            // the client could only ever drift out of agreement with it.
            'workflows' => collect(app(WorkflowRegistry::class)->all())
                ->map(fn (WorkflowDefinition $definition): array => [
                    'name' => $definition->name,
                    'label' => $definition->label,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The loop's live actions, each carrying its routine when the loop records
     * through a workflow that configures one.
     *
     * `routine` is null — not an empty list — for a loop with no workflow, so
     * the client can tell "this loop does not configure actions" from "this
     * action's routine is empty". A plain loop pays for neither the eager load
     * nor the extra key.
     *
     * The eager load names no columns. A `->with('actionExercises.exercise:id,name')`
     * would return null for anything unnamed forever with the suite green —
     * the trap this project has been bitten by three times.
     *
     * `upcomingOccurrences` is loaded unconditionally, beside the routine's
     * conditional load, because every action here reads `next_occurrence_at`
     * whether or not the loop configures a routine — without it,
     * `nextOccurrenceAt()` issues its own query per action.
     *
     * @return list<array<string, mixed>>
     */
    private function actionLayer(Intention $intention, string $timezone): array
    {
        $configuresActions = app(WorkflowRegistry::class)->for($intention->workflow)?->config !== null;

        return $intention->actions()
            ->where('status', '!=', Action::STATUS_ARCHIVED)
            ->with('upcomingOccurrences')
            ->when($configuresActions, fn (Builder $query) => $query->with('actionExercises.exercise'))
            ->get()
            ->map(fn (Action $action): array => [
                'id' => $action->id,
                'title' => $action->title,
                'recurrence' => $action->recurrence,
                'schedule_kind' => $action->metadata['schedule_kind'] ?? null,
                'anchor' => $action->metadata['anchor'] ?? null,
                'time' => $action->series_started_at?->timezone($timezone)->format('H:i'),
                // The anchor, twice, for two different readers. `date` fills
                // the editor's date input, which needs a Y-m-d string and must
                // not get one from client-side date maths: parsing an ISO
                // string in the browser's zone and reformatting is how a 23rd
                // becomes a 22nd for anyone west of the owner's zone.
                // `starts_at` is the instant, which is what the cadence line
                // compares against now to decide whether the series has begun.
                'date' => $action->series_started_at?->timezone($timezone)->format('Y-m-d'),
                'starts_at' => $action->series_started_at?->timezone($timezone)->toIso8601String(),
                'next_occurrence_at' => $action->nextOccurrenceAt()?->timezone($timezone)->toIso8601String(),
                'routine' => $configuresActions
                    ? $action->actionExercises
                        ->map(fn (ActionExercise $row): array => [
                            'id' => $row->id,
                            'exercise_id' => $row->exercise_id,
                            'exercise_name' => $row->exercise?->name,
                            'position' => $row->position,
                            'target_sets' => $row->target_sets,
                            'target_reps' => $row->target_reps,
                        ])
                        ->values()
                        ->all()
                    : null,
            ])->values()->all();
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
