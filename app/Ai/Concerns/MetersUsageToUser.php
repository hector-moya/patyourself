<?php

namespace App\Ai\Concerns;

use App\Models\User;

/**
 * Attributes a non-conversational agent's LLM spend to a specific user.
 *
 * The GuardCoachUsage middleware bills the authenticated user, falling back to
 * the agent's conversationParticipant() when there is no HTTP session (the
 * queued coaching path). Strategist/Summarizer have no conversation memory, so
 * this trait supplies just the participant hook the guard needs — letting the
 * background coaching pass be metered to the loop owner without pulling in the
 * full RemembersConversations machinery.
 */
trait MetersUsageToUser
{
    protected ?User $billedUser = null;

    /**
     * Attribute this agent's usage to the given user.
     */
    public function forUser(User $user): static
    {
        $this->billedUser = $user;

        return $this;
    }

    /**
     * The user GuardCoachUsage should bill — null until forUser() is called.
     */
    public function conversationParticipant(): ?User
    {
        return $this->billedUser;
    }
}
