<?php

namespace App\Actions;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Records the outcome of an action — completed, failed, or skipped — and
 * advances the action's own status to match. A log is an immutable event; on
 * failure it carries the user-stated reason, which is the raw material the
 * versioned-strategy logic and the rolling summaries later feed on.
 *
 * This is the only place the logging flow writes to the database. It is
 * deliberately side-effect-free beyond recording: revising a strategy and
 * refolding a summary both make LLM calls, so they run as separate, explicit
 * steps rather than synchronously inside every log.
 */
final readonly class LogAction
{
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
                $action->update(['status' => $status]);
            }

            return $log;
        });
    }

    /**
     * How an outcome moves the action card. A failure leaves it open so the
     * user can retry (or a strategy revision can supersede it later); only a
     * completion or a skip closes it out.
     */
    private function actionStatusFor(string $outcome): ?string
    {
        return match ($outcome) {
            ActionLog::OUTCOME_COMPLETED => Action::STATUS_COMPLETED,
            ActionLog::OUTCOME_SKIPPED => Action::STATUS_SKIPPED,
            default => null,
        };
    }
}
