<?php

namespace App\Services\Coach\Exceptions;

use App\Models\User;

/**
 * Raised when a user has spent their rolling token budget. A CoachException so
 * existing catch sites still degrade gracefully, but rendered as HTTP 429 so the
 * client can tell "slow down / out of budget" apart from a provider failure.
 */
class CoachQuotaException extends CoachException
{
    public static function dailyTokenBudget(User $user, int $budget, int $used): self
    {
        return new self(
            "Daily coach token budget of {$budget} reached for user [{$user->id}] (used {$used})."
        );
    }
}
