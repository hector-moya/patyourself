<?php

namespace App\Policies;

use App\Models\Occurrence;
use App\Models\User;

/**
 * An occasion belongs to the user who owns the loop its action sits on.
 * Logging one is gated on that ownership, exactly as logging an action is.
 */
class OccurrencePolicy
{
    public function log(User $user, Occurrence $occurrence): bool
    {
        return $occurrence->action->intention->user_id === $user->id;
    }
}
