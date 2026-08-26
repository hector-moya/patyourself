<?php

namespace App\Actions;

use App\Events\ActionLogged;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Occurrence;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Records the outcome of one occasion — completed, failed, or skipped. A log
 * is an immutable event; on failure it carries the user-stated reason
 * verbatim, which is the raw material the next strategy version is written
 * from.
 *
 * An outcome attaches to an {@see Occurrence}, not to the action, which is what
 * dates it by the occasion it describes rather than by the moment it was typed.
 * The action row is the standing prescription and this flow never writes to it:
 * completing one occasion says nothing about the prescription itself.
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
    /**
     * @param  array<string, mixed>  $data  Validated outcome / reason / context / metadata.
     * @param  Occurrence|null  $occurrence  The occasion being logged. Null means "the
     *                                       live slot" — today's unlogged occasion —
     *                                       which is what the web and JSON API surfaces
     *                                       mean when they log an action card.
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

            $this->markCueAnswered($user, $action, $occurrence);

            ActionLogged::dispatch($user, $action, $log);

            return $log;
        });
    }

    /**
     * The occasion a caller means when it names none: today's, which is what a
     * card on screen is about. Latest first, so a day with two slots resolves
     * the later one — the one whose moment has most recently passed.
     *
     * A cue-anchored action has no grid, and a day whose slots are all logged
     * has none left, so both fall through to a slot stamped now. That is how a
     * second log on an already-answered day is recorded as its own occasion
     * rather than colliding with the first.
     */
    private function liveSlotFor(Action $action): Occurrence
    {
        $now = Date::now();
        $timezone = $action->intention?->user?->timezone ?? (string) config('app.timezone');
        $localNow = Date::now($timezone);

        $slot = $action->occurrences()
            ->unlogged()
            ->where('scheduled_for', '<=', $now)
            ->where('scheduled_for', '>=', $localNow->copy()->startOfDay()->utc())
            ->orderByDesc('scheduled_for')
            ->first();

        return $slot ?? $this->freeSlotAt($action, $now);
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
     * Logging any outcome answers the cue for that occasion, so mark its
     * unread notification(s) read. Matches on occurrence_id, falling back to
     * action_id so a cue delivered before occasions carried their own id still
     * clears when answered. Filtered in memory (unread sets are tiny) to stay
     * portable across database drivers.
     */
    private function markCueAnswered(User $user, Action $action, Occurrence $occurrence): void
    {
        $user->unreadNotifications()->get()
            ->filter(function (DatabaseNotification $notification) use ($action, $occurrence): bool {
                $occurrenceId = $notification->data['occurrence_id'] ?? null;

                return $occurrenceId === null
                    ? ($notification->data['action_id'] ?? null) === $action->id
                    : $occurrenceId === $occurrence->id;
            })
            ->each->markAsRead();
    }
}
