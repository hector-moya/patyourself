<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Services\Progress\LoopProgress;
use App\Services\Progress\RecordCuts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The record for one loop: what happened, and what the history shows.
 *
 * The split against `/loops/{loop}` is by tense, and it is the whole point of
 * this route existing. The loop page states what the loop *is* — its chain, the
 * strategy running on it, its schedule, its versions. This page reports what
 * *occurred*: the count, the cuts, the words written at the time, the
 * reflection, and the chronology. Nothing appears on both. A field describing
 * intent belongs there; a field describing an event belongs here.
 *
 * Reached from a progress card, a loop card or the loop itself — never from the
 * nav, because it is always about a loop you were already looking at.
 */
class LoopRecordController extends Controller
{
    /** The chronology's default depth, and its ceiling under `?history=all`. */
    private const HISTORY_PAGE = 30;

    private const HISTORY_MAX = 500;

    /** Reasons shown in the sidebar before it defers to the chronology. */
    private const REASON_PREVIEW = 4;

    private const NOTE_LIMIT = 50;

    public function __invoke(
        Request $request,
        Intention $intention,
        LoopProgress $progress,
        RecordCuts $cuts,
    ): Response {
        Gate::authorize('view', $intention);

        $intention->load(['activeStrategy', 'activeAction', 'latestSummary']);

        $timezone = $request->user()->timezone ?? (string) config('app.timezone');
        $showingAll = $request->query('history') === 'all';

        $logs = $this->logs($intention, $timezone);
        $reflection = $intention->latestSummary;

        // Only the occasions that decided something reach the cuts. A skip
        // never happened, so it belongs in no denominator anywhere on this
        // page — it is counted once, in the provenance line, and drawn as a
        // dashed cell in the strip.
        $decided = $logs->whereIn('outcome', [
            ActionLog::OUTCOME_COMPLETED,
            ActionLog::OUTCOME_FAILED,
        ]);

        return Inertia::render('loops/record', [
            'loop' => [
                'id' => $intention->id,
                'title' => $intention->title,
                'type' => $intention->type,
                'version' => $intention->activeStrategy?->version,
                'day_of_experiment' => $intention->activeStrategy?->dayOfExperiment(),
                // What the record is a record *of*. Named here so a row saying
                // "Held" has something to have held.
                'action_title' => $intention->activeAction?->title,
            ],
            // The running version's own figures, for the same reason the
            // progress card uses them: a lifetime rate set against an earlier
            // version contains that version.
            'progress' => $progress->forCurrentVersion($intention) ?? $progress->forLoop($intention),
            'previous_version' => $progress->previousDecidedVersion($intention),
            'cuts' => $cuts->for($decided),
            // Verbatim, newest first. The most valuable content on the page,
            // and the only thing on it written by the user at the moment it
            // mattered.
            'reasons' => $this->reasons($logs),
            'reasons_total' => $logs
                ->where('outcome', ActionLog::OUTCOME_FAILED)
                ->filter(fn (array $log): bool => $log['reason'] !== null)
                ->count(),
            'reflection' => $reflection === null ? null : [
                'content' => $reflection->content,
                'window_start' => $reflection->window_start?->toIso8601String(),
                'window_end' => $reflection->window_end?->toIso8601String(),
                'events_count' => $reflection->events_count,
            ],
            'entries' => $this->chronology($intention, $logs, $showingAll, $timezone),
            'occasions_total' => $logs->count(),
            'showing_all_history' => $showingAll,
        ]);
    }

    /**
     * Every outcome on the loop, newest occasion first.
     *
     * Dated by the occasion, not by `logged_at` — which is what puts a
     * caught-up entry where it belongs instead of bunching it with everything
     * else typed in the same sitting. A log from before occurrences existed
     * falls back to when it was typed, the only date that model recorded.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function logs(Intention $intention, string $timezone): Collection
    {
        return ActionLog::query()
            ->with(['occurrence', 'action.strategy'])
            ->whereHas('action', fn (Builder $query) => $query->where('intention_id', $intention->id))
            ->get()
            ->map(function (ActionLog $log) use ($timezone): array {
                $occurredAt = ($log->occurrence?->scheduled_for ?? $log->logged_at)->timezone($timezone);
                $loggedAt = $log->logged_at->timezone($timezone);

                return [
                    'id' => $log->id,
                    'occurred_at' => $occurredAt,
                    'logged_at' => $loggedAt,
                    'action_title' => $log->action->title,
                    'outcome' => $log->outcome,
                    'reason' => $log->reason,
                    'context' => $log->context,
                    'context_fields' => $log->context_fields,
                    'strategy_version' => $log->action->strategy?->version,
                    // Said on the row when it is true, because an occasion
                    // answered the next morning is a different kind of record
                    // from one answered as it happened.
                    'logged_later' => ! $occurredAt->isSameDay($loggedAt),
                ];
            })
            ->sortByDesc(fn (array $log): string => $log['occurred_at']->toDateTimeString())
            ->values();
    }

    /**
     * The words written when it did not hold, newest first.
     *
     * Unedited: no capitalisation fixed, no sentence tidied, nothing
     * summarised. Cleaning them here would be the same mistake as cleaning them
     * on the way in — they are evidence, and the next version gets argued from
     * them.
     *
     * @param  Collection<int, array<string, mixed>>  $logs
     * @return list<array{id: int, reason: string, occurred_at: string}>
     */
    private function reasons(Collection $logs): array
    {
        return $logs
            ->where('outcome', ActionLog::OUTCOME_FAILED)
            ->filter(fn (array $log): bool => $log['reason'] !== null)
            ->take(self::REASON_PREVIEW)
            ->map(fn (array $log): array => [
                'id' => $log['id'],
                'reason' => $log['reason'],
                'occurred_at' => $log['occurred_at']->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Occasions and notes on one timeline, newest first.
     *
     * Interleaved rather than listed apart because a note is almost always
     * *about* the days around it — "the weekend version of this is a different
     * animal" means nothing filed in a separate list from the weekend it
     * describes.
     *
     * The cap applies to occasions only. Notes are few and are the one thing on
     * this page the user wrote deliberately rather than in answer to a prompt,
     * so they are never the row that falls off the end.
     *
     * @param  Collection<int, array<string, mixed>>  $logs
     * @return list<array<string, mixed>>
     */
    private function chronology(Intention $intention, Collection $logs, bool $showingAll, string $timezone): array
    {
        $occasions = $logs
            ->take($showingAll ? self::HISTORY_MAX : self::HISTORY_PAGE)
            ->map(fn (array $log): array => [
                'kind' => 'occasion',
                'id' => $log['id'],
                'occurred_at' => $log['occurred_at']->toIso8601String(),
                'logged_at' => $log['logged_at']->toIso8601String(),
                'logged_later' => $log['logged_later'],
                'action_title' => $log['action_title'],
                'outcome' => $log['outcome'],
                'reason' => $log['reason'],
                'context' => $log['context'],
                'context_fields' => $log['context_fields'],
                'strategy_version' => $log['strategy_version'],
                'sort_at' => $log['occurred_at']->toDateTimeString(),
            ]);

        $notes = $intention->notes()->limit(self::NOTE_LIMIT)->get()
            ->map(fn (Note $note): array => [
                'kind' => 'note',
                'id' => $note->id,
                'occurred_at' => $note->created_at->timezone($timezone)->toIso8601String(),
                'body' => $note->body,
                'sort_at' => $note->created_at->timezone($timezone)->toDateTimeString(),
            ]);

        return $occasions
            ->concat($notes)
            ->sortByDesc(fn (array $entry): string => $entry['sort_at'])
            ->values()
            ->map(function (array $entry): array {
                unset($entry['sort_at']);

                return $entry;
            })
            ->all();
    }
}
