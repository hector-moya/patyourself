<?php

namespace App\Policies;

use App\Models\Strategy;
use App\Models\User;

/**
 * A strategy version is private to whoever owns the loop it belongs to.
 *
 * Separate from {@see IntentionPolicy} because the verdict route is keyed on a
 * strategy rather than on a loop: the version is what carries the verdict, and
 * routing through the loop would let a caller pass a loop they own together
 * with a version they do not.
 */
class StrategyPolicy
{
    public function update(User $user, Strategy $strategy): bool
    {
        return $strategy->intention?->user_id === $user->id;
    }
}
