<?php

namespace App\Actions;

use App\Events\ActionLogged;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records the outcome of one occasion — completed, failed, or skipped — and
 * advances the action's own status to match. A log is an immutable event; on
 * failure it carries the user-stated reason verbatim, which is the raw material
 * the next strategy version is written from.
 *
 * An outcome attaches to an {@see Occurrence}, not to the action, which is what
 * dates it by the occasion it describes rather than by the moment it was typed.
 * The action row stays the standing prescription and its `scheduled_for` stays
 * the next-due pointer.
 *
 * A recurring action still rolls that pointer forward when its live slot is
 * resolved (the SP2 trigger engine's recurrence mechanic), and one-off and
 * anchored actions still close. But catching up an *older* occasion never moves
 * the pointer — that pointer is what the trigger engine and the action cards
 * read, and a three-day-old log says nothing about what is due next.
 *
 * Logging an outcome also marks the action's in-app "due now" notification read
 * (the cue is answered).
 *
 * This is the only place the logging flow writes to the database. It is
 * deliberately free of LLM side-effects — nothing here makes a model call;
 * starting the next experiment is a separate, explicit action the owner takes.
 */
final readonly class LogAction
{
    public function __construct(private Schedule $schedule) {}

    /**
     * @param  array<string, mixed>  $data  Validated outcome / reason / context / metadata.
     * @param  Occurrence|null  $occurrence  The occasion being logged. Null means "the
     *                                       live slot" — the one the action's next-due
     *                                       pointer currently sits on — which is what the
     *                                       web and JSON API surfaces mean when they log
     *                                       an action card.
     */
    public function handle(User $user, Action $action, array $data, ?Occurrence $occurrence = null): ActionLog
    {
        return DB::transaction(function () use ($user, $action, $data, $occurrence): ActionLog {
            $occurrence ??= $this->liveSlotFor($action);

            $log = $action->logs()->create([
                'user_id' => $user->id,
                'occurrence_id' => $occurrence->id,
                'outcome' => $data['outcome'],
                // Verbatim. Never trimmed, squished or sentence-cased: this is
                // the raw material the next strategy version is written from.
                'reason' => $data['reason'] ?? null,
                'context' => $data['context'] ?? null,
                'context_fields' => $data['context_fields'] ?? null,
                'logged_at' => Date::now(),
                'metadata' => $data['metadata'] ?? null,
            ]);

            $status = $this->actionStatusFor($data['outcome']);

            if ($status !== null && $this->isLiveSlot($action, $occurrence)) {
                $this->closeOrRearm($user, $action, $status);
            }

            $this->markCueAnswered($user, $action);

            ActionLogged::dispatch($user, $action, $log);

            return $log;
        });
    }

    /**
     * The occasion a caller means when it names none: the slot the action's
     * next-due pointer sits on. A cue-anchored action has no schedule, so its
     * occasion is stamped now; and a slot that already carries an outcome gets
     * the same treatment, so a second log on an open (failed) slot is recorded
     * as its own occasion rather than colliding with the first.
     */
    private function liveSlotFor(Action $action): Occurrence
    {
        if ($action->scheduled_for !== null) {
            $slot = Occurrence::query()->firstOrCreate([
                'action_id' => $action->id,
                'scheduled_for' => $action->scheduled_for,
            ]);

            if (! $slot->isLogged()) {
                return $slot;
            }
        }

        return $this->freeSlotAt($action, Date::now());
    }

    /**
     * The first unlogged occasion at or after `$from` for this action. Occasions
     * are stored to the second, so two logs made inside the same second would
     * otherwise collide on the unique (action_id, scheduled_for) index.
     */
    private function freeSlotAt(Action $action, DateTimeInterface $from): Occurrence
    {
        $stamp = CarbonImmutable::instance($from)->startOfSecond();

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $slot = Occurrence::query()->firstOrCreate([
                'action_id' => $action->id,
                'scheduled_for' => $stamp,
            ]);

            if (! $slot->isLogged()) {
                return $slot;
            }

            $stamp = $stamp->addSecond();
        }

        throw new RuntimeException('Could not find a free occurrence slot for action '.$action->id.'.');
    }

    /**
     * Whether this occasion is the one the action is currently pointing at.
     * Catching up an older occasion must never move the next-due pointer.
     *
     * A cue-anchored action has no pointer and so no earlier occasion to be
     * behind: every log on it is the current one, and it closes as it always
     * did.
     */
    private function isLiveSlot(Action $action, Occurrence $occurrence): bool
    {
        if ($action->scheduled_for === null) {
            return true;
        }

        return $occurrence->scheduled_for->greaterThanOrEqualTo($action->scheduled_for);
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
