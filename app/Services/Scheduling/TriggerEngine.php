<?php

namespace App\Services\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use Illuminate\Database\Eloquent\Builder;

/**
 * The trigger engine: scans for actions whose scheduled fire time has arrived
 * and transitions them pending -> active so they surface as live "due" to-dos.
 * Firing is idempotent — each row is flipped with a guarded conditional update,
 * so an overlapping or repeated run fires every occurrence at most once. The
 * actions:fire command runs this every minute.
 *
 * SP2 does nothing beyond this in-app state transition. Recurrence roll-forward
 * happens when an occurrence is resolved (see App\Actions\LogAction); rich
 * notification delivery is SP3.
 */
final class TriggerEngine
{
    /**
     * Fire every due, pending action belonging to an active intention. Returns
     * the number actually fired (won by this run's guarded update).
     */
    public function fireDueActions(): int
    {
        $due = Action::query()
            ->where('status', Action::STATUS_PENDING)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->whereHas('intention', function (Builder $query): void {
                $query->where('status', Intention::STATUS_ACTIVE);
            })
            ->get();

        $fired = 0;

        foreach ($due as $action) {
            if ($this->fire($action)) {
                $fired++;
            }
        }

        return $fired;
    }

    /**
     * Atomically flip one action pending -> active. Returns true only for the
     * run whose guarded update actually changed the row (the fire owner); a
     * concurrent or repeated run sees 0 affected rows and returns false.
     */
    private function fire(Action $action): bool
    {
        $metadata = array_merge($action->metadata ?? [], [
            'fired_at' => now()->toIso8601String(),
        ]);

        $affected = Action::query()
            ->whereKey($action->getKey())
            ->where('status', Action::STATUS_PENDING)
            ->update([
                'status' => Action::STATUS_ACTIVE,
                'metadata' => json_encode($metadata),
            ]);

        return $affected === 1;
    }
}
