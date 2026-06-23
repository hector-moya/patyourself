<?php

namespace App\Listeners;

use App\Actions\ReviseStrategy;
use App\Actions\UpdateRollingSummary;
use App\Events\ActionLogged;
use App\Models\ActionLog;
use App\Models\Strategy;
use App\Notifications\StrategyRevisedNotification;
use App\Services\Coach\Exceptions\CoachQuotaException;
use App\Services\Coach\OutcomeStreak;
use App\Services\Coach\Strategy\StrategyTransitionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SP4 — the auto-coaching closure. When an action is logged, this queued listener
 * runs the LLM-bearing coaching pass off the request: it always refolds the loop's
 * rolling summary (a no-op when nothing new), then, on a deterministic outcome
 * streak, revises the active strategy and notifies the owner. Failures here never
 * affect the already-committed log.
 */
final class RunCoachingClosure implements ShouldQueue
{
    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(
        private readonly UpdateRollingSummary $updateSummary,
        private readonly ReviseStrategy $reviseStrategy,
        private readonly OutcomeStreak $streak,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ActionLogged $event): void
    {
        $intention = $event->action->intention;

        // Serialize coaching per loop so a double-delivered job never double-spends
        // LLM tokens; the 75s TTL outlives one coaching pass (LLM timeout is 60s) so
        // the lock isn't released mid-call. The work is idempotent if it cannot be held.
        Cache::lock("coaching:intention:{$intention->id}", 75)->block(5, function () use ($intention): void {
            try {
                $this->updateSummary->handle($intention);

                $active = $intention->activeStrategy()->first();

                if ($active === null) {
                    return;
                }

                [$outcome, $run, $reason] = $this->streak->forStrategy($active);

                try {
                    $revised = $this->reviseFor($active, $outcome, $run, $reason);
                } catch (StrategyTransitionException $e) {
                    // Already superseded by a concurrent run — skip the revision.
                    // The streak persists, so the next qualifying log retries.
                    Log::info('Coaching closure skipped revision: '.$e->getMessage(), [
                        'intention_id' => $intention->id,
                    ]);

                    return;
                }

                if ($revised !== null) {
                    $intention->user->notify(new StrategyRevisedNotification($revised));
                }
            } catch (CoachQuotaException $e) {
                // The loop owner is over budget — skip the whole pass (summary and
                // revision). The streak persists, so the next qualifying log retries
                // once the rolling-24h window frees.
                Log::info('Coaching closure skipped (over budget): '.$e->getMessage(), [
                    'intention_id' => $intention->id,
                ]);
            }
        });
    }

    /**
     * @throws StrategyTransitionException
     * @throws CoachQuotaException
     */
    private function reviseFor(Strategy $active, ?string $outcome, int $run, ?string $reason): ?Strategy
    {
        if ($outcome === ActionLog::OUTCOME_FAILED && $run >= (int) config('services.coach.fail_streak', 2)) {
            return $this->reviseStrategy->restrategizeOnFailure($active, $reason ?? '');
        }

        if ($outcome === ActionLog::OUTCOME_COMPLETED && $run >= (int) config('services.coach.stack_streak', 5)) {
            return $this->reviseStrategy->stackOnSuccess($active);
        }

        return null;
    }
}
