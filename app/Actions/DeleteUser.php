<?php

namespace App\Actions;

use App\Models\ActionExercise;
use App\Models\Intention;
use App\Models\PerformedSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permanently removes an account and everything hanging off it. The only place
 * the account-deletion flow writes to the database.
 *
 * Nearly all of that is the database's own work: loops, strategies, actions,
 * occasions, logs, summaries and the person's own catalogue additions all
 * cascade from `users`. One pair does not compose, and it is the whole reason
 * this class exists rather than a bare `$user->delete()`:
 *
 *     users      → exercises        cascade
 *     exercises  ← performed_sets   restrict
 *     exercises  ← action_exercises restrict
 *
 * Someone who added their own exercise, put it in a routine and lifted against
 * it holds rows that forbid the deletion of a row their own deletion demands.
 * Both branches start at `users`, and the order an engine walks two sibling
 * cascades in is undefined — SQLite refuses outright; whether MySQL happens to
 * get away with it is luck, not design.
 *
 * The restricts are not the thing to relax. They are the training spec's
 * "history does not vanish" rule: deleting a catalogue entry hides it from new
 * routines, it does not silently empty the sessions already written against it.
 * So the departing account's own dependent rows go first, explicitly, and the
 * cascades are left with nothing to disagree about. Both would have been
 * removed by the `occurrences` and `actions` cascades anyway — this only fixes
 * *when*.
 *
 * Scoped to this user's own rows and no further. If somebody else's set points
 * at an exercise this account added, the restrict is doing exactly its job and
 * the deletion must fail rather than rewrite a stranger's record.
 *
 * All of it in one transaction, because a half-deleted account is worse than an
 * intact one, and because the caller signs the user out on the strength of this
 * returning.
 */
final readonly class DeleteUser
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            /** @var callable(Builder<Intention>): mixed $theirs */
            $theirs = static fn (Builder $loop) => $loop->where('user_id', $user->id);

            PerformedSet::query()
                ->whereHas('occurrence.action.intention', $theirs)
                ->delete();

            ActionExercise::query()
                ->whereHas('action.intention', $theirs)
                ->delete();

            $user->delete();
        });
    }
}
