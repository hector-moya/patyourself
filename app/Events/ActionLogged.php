<?php

namespace App\Events;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after an action's outcome is durably logged (see {@see LogAction}).
 * Carries the full context so an async listener can run the coaching closure (SP4)
 * without re-querying. ShouldDispatchAfterCommit: if LogAction's transaction rolls
 * back, no coaching is triggered.
 */
final class ActionLogged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Action $action,
        public readonly ActionLog $log,
    ) {}
}
