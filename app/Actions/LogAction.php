<?php

namespace App\Actions;

use App\Events\ActionLogged;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Scheduling\ResolvesOccasionSlot;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private ResolvesOccasionSlot $slots) {}

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
            $occurrence ??= $this->slots->liveSlotFor($action);

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
     * Logging an outcome answers the cue for that occasion — and every earlier
     * unanswered cue for the same action.
     *
     * The narrower rule (clear only this occasion's cue) leaves one unread
     * behind per missed day, and the shared unread count renders as a badge in
     * the primary navigation: a running tally of the unlogged set, which is
     * exactly the nagging the notebook does not do. Nothing here touches the
     * missed occasions themselves — they stay unlogged and wait quietly on
     * /catch-up.
     *
     * Bounded to "at or before" rather than "all of them" so catching up
     * Tuesday from /catch-up leaves today's fresh cue standing, and bounded to
     * this action so answering dinner says nothing about lunch.
     *
     * Falls back to action_id for a cue delivered before occasions carried
     * their own id: that payload has no occasion to place in time, so the
     * action match is all there is. Filtered in memory (unread sets are tiny)
     * to stay portable across database drivers.
     */
    private function markCueAnswered(User $user, Action $action, Occurrence $occurrence): void
    {
        $unread = $user->unreadNotifications()->get();

        $cued = $unread
            ->pluck('data.occurrence_id')
            ->filter(static fn ($id): bool => $id !== null)
            ->map(static fn ($id): int => (int) $id)
            ->all();

        // One query resolves which of those cues sit at or before the occasion
        // just answered. Scoped by action_id so another action's occasions can
        // never be swept up by the time comparison alone.
        $answered = $cued === [] ? [] : Occurrence::query()
            ->whereKey($cued)
            ->where('action_id', $action->id)
            ->where('scheduled_for', '<=', $occurrence->scheduled_for)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $unread
            ->filter(function (DatabaseNotification $notification) use ($action, $answered): bool {
                $occurrenceId = $notification->data['occurrence_id'] ?? null;

                return $occurrenceId === null
                    ? ($notification->data['action_id'] ?? null) === $action->id
                    : in_array((int) $occurrenceId, $answered, true);
            })
            ->each->markAsRead();
    }
}
