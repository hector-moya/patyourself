<?php

namespace App\Events;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after an action's outcome is durably logged (see {@see LogAction}).
 * Carries the user, action and log so the queued coaching closure (SP4) has its
 * full context. SerializesModels: the queued listener re-fetches each model fresh
 * by key when the job runs, so only identifiers cross the queue (not loaded
 * relations). ShouldDispatchAfterCommit: if LogAction's transaction rolls back, no
 * coaching is triggered.
 */
final class ActionLogged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Action $action,
        public readonly ActionLog $log,
    ) {}
}
