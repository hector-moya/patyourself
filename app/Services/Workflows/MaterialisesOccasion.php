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
     * Today's slot for this action — due or still ahead of the clock — or a new
     * occasion stamped now when today holds none.
     *
     * Resolved through the collaborator the logging flow uses, not a second
     * implementation: that shared `firstOrCreate` is what makes two taps in the
     * same second unable to mint two occasions. It is a *sibling* method there,
     * though, not the same one. Logging resolves and writes in one breath, so it
     * only ever wants a slot already due; a session resolves at the start and is
     * logged at the end, and someone warming up at 18:00 for a 19:00 slot must
     * attach to that slot rather than mint a phantom beside it.
     *
     * **What this does not guarantee, and the contract that follows.** The sets
     * land on the occasion returned here. The verdict lands on the same one only
     * if the caller passes this occurrence to
     * `App\Actions\LogAction::handle(User, Action, array, ?Occurrence)` — its
     * fourth parameter. Left null, the verdict resolves itself afresh at the
     * moment it is pressed, which is a different question asked at a different
     * time and may well be a different answer.
     *
     * So: **a recording surface must hold the occurrence it opened with and hand
     * it back to `LogAction` when the verdict is pressed.** That is the contract,
     * and it is pinned by
     * `Tests\Feature\Workflows\MaterialisesOccasionTest::test_the_verdict_lands_on_the_session_only_when_the_caller_names_it`.
     */
    public function forAction(Action $action): Occurrence
    {
        return $this->slots->todaysSlotFor($action);
    }
}
