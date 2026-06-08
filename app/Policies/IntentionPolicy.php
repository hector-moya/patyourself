<?php

namespace App\Policies;

use App\Models\Intention;
use App\Models\User;

/**
 * A loop is private to the user who owns it — every read and write is gated on
 * ownership. Shared by both the web and API controllers.
 */
class IntentionPolicy
{
    public function view(User $user, Intention $intention): bool
    {
        return $this->owns($user, $intention);
    }

    public function update(User $user, Intention $intention): bool
    {
        return $this->owns($user, $intention);
    }

    public function delete(User $user, Intention $intention): bool
    {
        return $this->owns($user, $intention);
    }

    private function owns(User $user, Intention $intention): bool
    {
        return $intention->user_id === $user->id;
    }
}
