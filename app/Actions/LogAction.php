<?php

namespace App\Actions;

use App\Events\ActionLogged;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Records the outcome of an action — completed, failed, or skipped — and
 * advances the action's own status to match. A log is an immutable event; on
 * failure it carries the user-stated reason, which is the raw material the
 * versioned-strategy logic and the rolling summaries later feed on.
 *
 * A recurring action does not close when an occurrence is completed or skipped:
 * it rolls forward in place to its next occurrence (the SP2 trigger engine's
 * recurrence mechanic). One-off and anchored actions close as before.
 *
 * Logging an outcome also marks the action's in-app "due now" notification read
 * (the cue is answered). It remains free of LLM side-effects.
 *
 * This is the only place the logging flow writes to the database. It is
 * deliberately free of LLM side-effects — revising a strategy and refolding a
 * summary both make model calls, so they run as separate, explicit steps.
 */
final readonly class LogAction
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @param  array<string, mixed>  $data  Validated outcome / reason / metadata.
     */
    public function handle(User $user, Action $action, array $data): ActionLog
    {
        return DB::transaction(function () use ($user, $action, $data): ActionLog {
            $log = $action->logs()->create([
                'user_id' => $user->id,
                'outcome' => $data['outcome'],
                'reason' => $data['reason'] ?? null,
                'logged_at' => Date::now(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $status = $this->actionStatusFor($data['outcome']);

            if ($status !== null) {
                $this->closeOrRearm($user, $action, $status);
            }

            $this->markCueAnswered($user, $action);

            ActionLogged::dispatch($user, $action, $log);

            return $log;
        });
    }

    /**
     * A completion or skip closes a one-off / anchored action, but rolls a
     * recurring action forward to its next occurrence (status back to pending,
     * scheduled_for fast-forwarded past any missed slots).
     */
    private function closeOrRearm(User $user, Action $action, string $closingStatus): void
    {
        $isRecurring = $action->recurrence !== null && $action->scheduled_for !== null;

        if (! $isRecurring) {
            $action->update(['status' => $closingStatus]);

            return;
        }

        $next = $this->schedule->nextAfter(
            $action->scheduled_for->toImmutable(),
            CarbonImmutable::now(),
            Recurrence::tryFromToken($action->recurrence),
            $user->timezone ?? (string) config('app.timezone'),
        );

        if ($next === null) {
            // Defensive: an unrecognised recurrence token — close it out.
            $action->update(['status' => $closingStatus]);

            return;
        }

        $action->update([
            'status' => Action::STATUS_PENDING,
            'scheduled_for' => $next,
        ]);
    }

    /**
     * How an outcome moves the action card. A failure leaves it open so the
     * user can retry (or a strategy revision can supersede it later); only a
     * completion or a skip closes — or, for a recurring action, re-arms — it.
     */
    private function actionStatusFor(string $outcome): ?string
    {
        return match ($outcome) {
            ActionLog::OUTCOME_COMPLETED => Action::STATUS_COMPLETED,
            ActionLog::OUTCOME_SKIPPED => Action::STATUS_SKIPPED,
            default => null,
        };
    }

    /**
     * Logging any outcome answers the "do this now" cue, so mark this action's
     * unread notification(s) read. Filtered in memory (unread sets are tiny) to
     * stay portable across database drivers.
     */
    private function markCueAnswered(User $user, Action $action): void
    {
        $user->unreadNotifications()->get()
            ->filter(fn (DatabaseNotification $notification): bool => ($notification->data['action_id'] ?? null) === $action->id)
            ->each->markAsRead();
    }
}
