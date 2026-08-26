<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Occurrence;
use Carbon\CarbonImmutable;

/**
 * One entry in today's list. A scheduled entry carries the occasion it is
 * about; a cue-anchored one has none, because an action with no schedule has
 * produced no occasion yet — logging it is what creates one.
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
