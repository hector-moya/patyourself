<?php

namespace App\Actions;

use App\Models\Action;
use App\Services\Scheduling\MaterialiseOccurrences;
use App\Services\Scheduling\TodaysOccasions;

/**
 * Retires an action without destroying what it produced.
 *
 * Deliberately not a delete. Occurrences hang off an action and outcomes hang
 * off occurrences, so deleting the row would cascade away the evidence — the
 * exact history this app exists to keep. Archiving already means "not live"
 * everywhere: {@see MaterialiseOccurrences} skips
 * archived actions, {@see TodaysOccasions} excludes them
 * too, and {@see StartExperiment} archives the prior action when a new
 * experiment begins.
 */
final readonly class ArchiveAction
{
    public function handle(Action $action): Action
    {
        $action->update(['status' => Action::STATUS_ARCHIVED]);

        return $action->refresh();
    }
}
