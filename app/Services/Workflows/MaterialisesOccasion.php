<?php

namespace App\Services\Workflows;

use App\Models\Action;
use App\Models\Occurrence;
use App\Services\Scheduling\ResolvesOccasionSlot;

/**
 * The occasion a workflow record hangs on, created if there is not one yet.
 *
 * Every workflow needs this, not just the gym: "beginning to record
 * materialises the occasion" is settled in the workflow architecture spec, and
 * nothing in this class is gym-specific. It lives beside WorkflowRegistry so
 * journalling or running reaches for the same rule rather than writing a second
 * copy of it.
 *
 * A record keys to an {@see Occurrence}, and a cue-anchored action ("train
 * after work") has no schedule, so it has produced none — pressing a verdict is
 * what creates one. Sets are ticked off during the session, long before anyone
 * presses Done or Missed, so beginning to record has to materialise the
 * occasion first.
 *
 * **Materialising must not create an ActionLog.** That is the whole point: the
 * occasion now exists to hang sets on, and the verdict is still pressed
 * separately, by a person, afterwards. One occasion, one log, unchanged.
 * `logCount` is what the companion ladder spends, so a workflow that could mint
 * a log by being more granular than "one occasion" would inflate the economy
 * every other loop is measured against.
 *
 * A session begun and abandoned leaves an unlogged occasion, which is
 * indistinguishable from any other occasion nobody got to and correctly ends up
 * on /catch-up.
 */
final readonly class MaterialisesOccasion
{
    public function __construct(private ResolvesOccasionSlot $slots) {}

    /**
     * Today's live slot for this action, or a new occasion stamped now when the
     * action has none.
     *
     * Resolved through the collaborator {@see LogAction} uses, not a second
     * implementation: that is what makes two taps in the same second unable to
     * mint two occasions, and what guarantees the occasion a set is recorded
     * against is the one the verdict later lands on.
     */
    public function forAction(Action $action): Occurrence
    {
        return $this->slots->liveSlotFor($action);
    }
}
