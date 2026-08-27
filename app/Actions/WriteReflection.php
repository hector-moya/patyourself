<?php

namespace App\Actions;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
use App\Models\Summary;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * Writes the loop's rolling narrative — one synthesis of what the record shows,
 * as opposed to the discrete observations {@see Note} holds.
 *
 * The caller supplies only the words. The window it covers and the number of
 * occasions inside it are derived here, because a narrative that could name its
 * own window could claim to have read evidence it never looked at.
 *
 * Append-only: every reflection is a new row, and {@see Intention::latestSummary()}
 * takes the most recent. Earlier reflections stay as the record of what was
 * believed at the time.
 */
final readonly class WriteReflection
{
    /**
     * @param  string  $content  Stored verbatim. Never trimmed, squished or
     *                           sentence-cased — it is a written account, and
     *                           the record keeps what was written.
     */
    public function handle(Intention $loop, string $content): Summary
    {
        $windowStart = $this->windowStartFor($loop);
        $windowEnd = Date::now();

        return $loop->summaries()->create([
            'user_id' => $loop->user_id,
            'scope' => Summary::SCOPE_INTENTION,
            'content' => $content,
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'events_count' => $this->occasionsBetween($loop, $windowStart, $windowEnd),
        ]);
    }

    /**
     * Where this reflection's window opens: the last one's close, so the
     * narrative picks up exactly where it left off. Failing that the current
     * experiment's start, and failing that the loop's own beginning — a loop
     * can legitimately be reflected on before its first experiment is written.
     */
    private function windowStartFor(Intention $loop): CarbonImmutable
    {
        $previous = $loop->summaries()
            ->where('scope', Summary::SCOPE_INTENTION)
            ->latest('id')
            ->first();

        $start = $previous?->window_end
            ?? $loop->activeStrategy?->created_at
            ?? $loop->created_at;

        return CarbonImmutable::instance($start);
    }

    /**
     * How many occasions the window actually contains.
     *
     * Dated by the occasion, not by when the outcome was typed — the same rule
     * loop-outcomes uses. Counting by `logged_at` would fold a catch-up session
     * into whichever window happened to be open when the user sat down, which
     * is not the stretch of time the narrative is about.
     *
     * Half-open (`start <` … `<= end`): an occasion sitting exactly on the
     * boundary belongs to the earlier window, which already reported it.
     */
    private function occasionsBetween(Intention $loop, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return ActionLog::query()
            ->whereHas('action', fn (Builder $action) => $action->where('intention_id', $loop->id))
            ->where(fn (Builder $log) => $log
                ->whereHas('occurrence', fn (Builder $occurrence) => $occurrence
                    ->where('scheduled_for', '>', $start)
                    ->where('scheduled_for', '<=', $end))
                ->orWhere(fn (Builder $orphan) => $orphan
                    ->whereDoesntHave('occurrence')
                    ->where('logged_at', '>', $start)
                    ->where('logged_at', '<=', $end)))
            ->count();
    }
}
