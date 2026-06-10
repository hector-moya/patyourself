<?php

namespace App\Actions;

use App\Ai\Agents\Summarizer;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Summary;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * Refreshes a loop's rolling summary. Gathers the action-log events that have
 * happened since the last snapshot, folds them (with the prior summary) into an
 * updated one via the Summarizer agent, and stores a new Summary snapshot
 * covering that window. This is the only place the rolling summary is written.
 *
 * Returns null when there is nothing new to fold, so callers can fire it freely
 * after each logged event without creating empty snapshots.
 */
final readonly class UpdateRollingSummary
{
    public function handle(Intention $intention): ?Summary
    {
        // Fetch fresh (not the cached property) so repeated calls on the same
        // instance see the snapshot the previous call wrote.
        $previous = $intention->latestSummary()->first();

        $query = $intention->actionLogs()
            ->where('action_logs.logged_at', '<=', Date::now())
            ->orderBy('action_logs.logged_at');

        if ($previous !== null) {
            $query->where('action_logs.logged_at', '>', $previous->window_end);
        }

        $events = $query->get();

        if ($events->isEmpty()) {
            return null;
        }

        // Windows track the events themselves, not wall-clock time, so they stay
        // contiguous across snapshots regardless of when this runs.
        $windowStart = $previous?->window_end ?? $events->first()->logged_at;
        $windowEnd = $events->last()->logged_at;

        $userPrompt = $this->userPrompt($intention, $events, $previous);
        $response = (new Summarizer)->prompt($userPrompt);

        $content = trim((string) ($response->structured['content'] ?? ''));

        if ($content === '') {
            throw CoachException::emptyResponse('summarizer');
        }

        $patterns = array_values(array_map('strval', $response->structured['patterns'] ?? []));

        return $intention->summaries()->create([
            'user_id' => $intention->user_id,
            'scope' => Summary::SCOPE_INTENTION,
            'content' => $content,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'events_count' => $events->count(),
            'metadata' => array_filter([
                'model' => $response->meta->model ?? null,
                'prompt_version' => Summarizer::PROMPT_VERSION,
                'patterns' => $patterns,
            ], static fn ($value): bool => $value !== null),
        ]);
    }

    /**
     * @param  iterable<ActionLog>  $events
     */
    private function userPrompt(Intention $intention, iterable $events, ?Summary $previous): string
    {
        $lines = [
            'Habit loop: '.$intention->title.' ('.$intention->type.')',
            'Cue: '.$intention->cue,
            'Craving: '.$intention->craving,
            'Response: '.$intention->response,
            'Reward: '.$intention->reward,
        ];

        if ($previous !== null && $previous->content !== '') {
            $lines[] = '';
            $lines[] = 'Prior rolling summary:';
            $lines[] = $previous->content;
        }

        $lines[] = '';
        $lines[] = 'New events since then (oldest first):';

        foreach ($events as $event) {
            $when = $event->logged_at instanceof Carbon
                ? $event->logged_at->toDateString()
                : (string) $event->logged_at;

            $line = '- ['.$when.'] '.$event->outcome;

            if ($event->reason !== null && $event->reason !== '') {
                $line .= ' — reason: '.$event->reason;
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
