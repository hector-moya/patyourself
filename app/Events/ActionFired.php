<?php

namespace App\Events;

use App\Models\Action;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised by the trigger engine when (and only when) it owns the transition that
 * fires an action pending -> active. Carries the freshly-refreshed Action so
 * listeners see the new status and metadata.fired_at. SP3 delivers the cue from
 * it; SP4 can add its own listeners.
 */
class ActionFired
{
    use Dispatchable;

    public function __construct(public readonly Action $action) {}
}
