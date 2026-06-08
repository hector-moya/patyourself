<?php

namespace App\Actions;

use App\Models\Intention;
use App\Models\Summary;
use App\Services\Coach\Summary\RollingSummaryService;
use Illuminate\Support\Facades\Date;

/**
 * Refreshes a loop's rolling summary. Gathers the action-log events that have
 * happened since the last snapshot, folds them (with the prior summary) into an
 * updated one via the coach, and stores a new Summary snapshot covering that
 * window. This is the only place the rolling summary is written.
 *
 * Returns null when there is nothing new to fold, so callers can fire it freely
 * after each logged event without creating empty snapshots.
 */
final readonly class UpdateRollingSummary
{
    public function __construct(private RollingSummaryService $summarizer) {}

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

        $authored = $this->summarizer->summarize($intention, $events, $previous);

        return $intention->summaries()->create([
            'user_id' => $intention->user_id,
            'scope' => Summary::SCOPE_INTENTION,
            'content' => $authored->content,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'events_count' => $events->count(),
            'metadata' => $authored->metadata(),
        ]);
    }
}
