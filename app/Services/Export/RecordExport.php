<?php

namespace App\Services\Export;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds one user's whole record as a plain array.
 *
 * The app's claim is that it is the record; a record you cannot take out is a
 * record someone else is holding. This is the single source of that payload —
 * both formatters render what this returns, so "is everything in the export?"
 * is a question about one class.
 *
 * Nothing here formats, rounds, summarises or scores. Text the user wrote is
 * copied out exactly as stored, whitespace and all.
 */
final readonly class RecordExport
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $loops = $user->intentions()
            ->with([
                'strategies' => fn ($query) => $query->orderBy('version'),
                'actions.occurrences.log',
                'notes' => fn ($query) => $query->orderBy('noted_at'),
                'summaries' => fn ($query) => $query->orderBy('created_at'),
            ])
            ->orderBy('created_at')
            ->get();

        return [
            'exported_at' => Carbon::now()->toIso8601String(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => $user->timezone,
            ],
            'loops' => $loops->map(fn (Intention $loop): array => $this->loop($loop))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loop(Intention $loop): array
    {
        return [
            'title' => $loop->title,
            'description' => $loop->description,
            'type' => $loop->type,
            'status' => $loop->status,
            'chain' => [
                'cue' => $loop->cue,
                'craving' => $loop->craving,
                'response' => $loop->response,
                'reward' => $loop->reward,
            ],
            'created_at' => $this->timestamp($loop->created_at),
            'strategies' => $loop->strategies->map(fn (Strategy $s): array => $this->strategy($s))->all(),
            'actions' => $loop->actions->map(fn (Action $a): array => $this->action($a))->all(),
            'notes' => $loop->notes->map(fn (Note $n): array => [
                'body' => $n->body,
                'noted_at' => $this->timestamp($n->noted_at),
            ])->all(),
            'reflections' => $loop->summaries->map(fn (Summary $s): array => [
                'scope' => $s->scope,
                'content' => $s->content,
                'window_start' => $this->timestamp($s->window_start),
                'window_end' => $this->timestamp($s->window_end),
                'events_count' => $s->events_count,
                'created_at' => $this->timestamp($s->created_at),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function strategy(Strategy $strategy): array
    {
        return [
            'version' => (int) $strategy->version,
            'status' => $strategy->status,
            'intervention_point' => $strategy->intervention_point,
            'approach' => $strategy->approach,
            'rationale' => $strategy->rationale,
            'change_reason' => $strategy->change_reason,
            'superseded_reason' => $strategy->superseded_reason,
            'review_at' => $this->timestamp($strategy->review_at),
            'verdict' => $strategy->verdict,
            'verdict_note' => $strategy->verdict_note,
            'created_at' => $this->timestamp($strategy->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function action(Action $action): array
    {
        return [
            'title' => $action->title,
            'description' => $action->description,
            'recurrence' => $action->recurrence,
            'status' => $action->status,
            'series_started_at' => $this->timestamp($action->series_started_at),
            'occurrences' => $action->occurrences
                ->sortBy('scheduled_for')
                ->values()
                ->map(fn (Occurrence $occurrence): array => [
                    'scheduled_for' => $this->timestamp($occurrence->scheduled_for),
                    'fired_at' => $this->timestamp($occurrence->fired_at),
                    'outcome' => $occurrence->log === null ? null : $this->outcome($occurrence->log),
                ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(ActionLog $log): array
    {
        return [
            'outcome' => $log->outcome,
            // Verbatim. The reason is the user's own words about why a strategy
            // did not hold, and it is the most important text in the record.
            'reason' => $log->reason,
            'logged_at' => $this->timestamp($log->logged_at),
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : null;
    }
}
