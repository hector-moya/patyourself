<?php

namespace App\Actions;

use App\Ai\Agents\Coach;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\User;
use App\Services\Coach\Chat\ChatResult;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Http\Client\HttpClientException;
use Laravel\Ai\Exceptions\AiException;

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

        try {
            $response = (new Coach)
                ->forUser($user)
                ->continueLastConversation($user)
                ->prompt($message);
        } catch (AiException|HttpClientException $e) {
            // HttpClientException covers both RequestException (HTTP error
            // status) and ConnectionException (timeout / DNS / refused).
            throw new CoachException(
                'The coach provider failed: '.$e->getMessage(),
                0,
                $e,
            );
        }

        $intention = Intention::query()
            ->whereIn('id', $this->collector->intentionIds())
            ->where('user_id', $user->id)
            ->latest()
            ->first();

        return new ChatResult($response->text, $intention);
    }
}
