<?php

namespace App\Services\Companion;

use App\Models\CompanionRemark;
use App\Models\Intention;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Which remark Blob relays on this visit, if any.
 *
 * A read, like everything else in this feature. Nothing is stored about what
 * has been shown beyond a single id the caller keeps in the session, so there
 * is no per-remark state to drift out of step with the record.
 *
 * With nothing eligible Blob says nothing. Not a placeholder and not a default
 * line — silence is the designed behaviour, including when the connector that
 * writes remarks is not set up at all.
 */
final readonly class CompanionRemarks
{
    /**
     * Where the caller keeps the id shown last time. The session rather than a
     * column: it is a display choice, not history, and a column would need
     * writing on every read of a screen that is otherwise a pure read.
     */
    public const SESSION_KEY = 'companion.last_remark_id';

    /**
     * A remark to show, avoiding the one shown last time.
     *
     * Random rather than newest-first: newest-first with an exclusion would
     * alternate between the two most recent remarks forever, and everything
     * written before them would never be heard again.
     *
     * `inRandomOrder()` and not `orderByRaw` — the grammar emits `RANDOM()` on
     * SQLite and `RAND()` on MySQL, and the test suite only ever exercises the
     * first of those.
     */
    public function nextFor(User $user, ?int $excluding = null): ?CompanionRemark
    {
        if ($excluding !== null) {
            $fresh = $this->eligible($user)->whereKeyNot($excluding)->inRandomOrder()->first();

            if ($fresh instanceof CompanionRemark) {
                return $fresh;
            }
        }

        // Nothing else is eligible. One remark repeats rather than Blob going
        // quiet, because silence after a remark reads as it being withdrawn.
        return $this->eligible($user)->inRandomOrder()->first();
    }

    /**
     * General remarks, plus those on a loop that is still active.
     *
     * A paused, archived or completed loop stops talking. The rows are never
     * deleted — history is append-only here as everywhere — and a loop coming
     * back to active brings its remarks back with it.
     *
     * @return HasMany<CompanionRemark, User>
     */
    private function eligible(User $user): HasMany
    {
        return $user->companionRemarks()
            ->where(static function (Builder $query): void {
                $query->whereNull('intention_id')
                    ->orWhereHas(
                        'intention',
                        static fn (Builder $loop) => $loop->where('status', Intention::STATUS_ACTIVE),
                    );
            });
    }
}
