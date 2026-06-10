<?php

namespace App\Actions;

use App\Ai\Agents\Coach;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\User;
use App\Services\Coach\Chat\ChatResult;

/**
 * Handles one chat turn end to end: prompts the Coach orchestrator inside the
 * user's durable conversation and collects any loops its tools authored. The
 * coach reads and writes through tools; this action just runs the turn.
 *
 * continueLastConversation() resolves the user's latest conversation ID from
 * the store (null for a fresh user). A null ID causes RemembersConversations
 * to start from an empty history; RememberConversation middleware then creates
 * the first conversation row after the turn completes.
 */
final readonly class RespondToChat
{
    public function __construct(private TurnCollector $collector) {}

    public function handle(User $user, string $message): ChatResult
    {
        $this->collector->flush();

        $response = (new Coach)
            ->forUser($user)
            ->continueLastConversation($user)
            ->prompt($message);

        $intention = Intention::query()
            ->whereIn('id', $this->collector->intentionIds())
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        return new ChatResult($response->text, $intention);
    }
}
