<?php

namespace App\Policies;

use App\Models\Action;
use App\Models\User;

/**
 * An action belongs to the user who owns its loop. Logging an outcome is gated
 * on that ownership; shared by both the web and API controllers.
 */
class ActionPolicy
{
    public function log(User $user, Action $action): bool
    {
        return $action->intention->user_id === $user->id;
    }

    public function update(User $user, Action $action): bool
    {
        return $action->intention->user_id === $user->id;
    }
}
