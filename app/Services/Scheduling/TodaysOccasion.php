<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Occurrence;
use Carbon\CarbonImmutable;

/**
 * One entry in today's list. A scheduled entry carries the occasion it is
 * about; a cue-anchored one carries none, because an action with no schedule
 * has produced no occasion *yet*.
 *
 * Yet, because that state ends. Something has to create the occasion an outcome
 * attaches to: pressing a verdict does it, and so does beginning to record,
 * because a recording session ticks its sets off long before anyone presses
 * Done. App\Services\Workflows\MaterialisesOccasion is that second path — named
 * in prose rather than imported, because a value object down here reaching up
 * at the workflow layer for the sake of a doc tag inverts the dependency.
 *
 * Once an occasion exists for today, the action is a *scheduled* entry here,
 * carrying it, and {@see TodaysOccasions} stops emitting the anchored one so
 * the same action is not offered twice. So a null `occurrence` does not mean
 * this action can never have one: it means it has none for today, and whatever
 * logs it will mint one.
 */
final readonly class TodaysOccasion
{
    public const DUE_NOW = 'due_now';

    public const UPCOMING = 'upcoming';

    public const ANCHORED = 'anchored';

    public function __construct(
        public Action $action,
        public ?Occurrence $occurrence,
        public ?CarbonImmutable $scheduledFor,
        public string $due,
    ) {}
}
