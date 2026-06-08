<?php

namespace App\Actions;

use App\Models\User;
use App\Services\Coach\Chat\ChatCoach;
use App\Services\Coach\Chat\ChatException;
use App\Services\Coach\Chat\ChatResult;
use App\Services\Coach\Exceptions\CoachException;

/**
 * Handles one chat turn end to end: runs the message through the coach and, when
 * the coach authors an Intention card, persists it for the user (reusing
 * AuthorIntention with the already-authored loop, so there is no second LLM
 * call). Returns the reply plus any persisted Intention to render inline.
 */
final readonly class RespondToChat
{
    public function __construct(
        private ChatCoach $chat,
        private AuthorIntention $author,
    ) {}

    /**
     * @param  list<array{role?: string, content?: string}>  $history  Prior turns, oldest first.
     *
     * @throws CoachException
     * @throws ChatException
     */
    public function handle(User $user, string $message, array $history = []): ChatResult
    {
        $reply = $this->chat->respond($message, $history);

        $intention = $reply->intention === null
            ? null
            : $this->author->handle($user, $message, [], $reply->intention);

        return new ChatResult($reply->message, $intention);
    }
}
