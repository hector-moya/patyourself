<?php

namespace App\Services\Coach\Chat;

use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Authoring\IntentionAuthoringException;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Data\Role;
use App\Services\Coach\Exceptions\CoachException;
use App\Services\Coach\Prompts\CoachPrompts;

/**
 * Runs a user's chat message through the coach and returns a structured reply:
 * a conversational message plus an optional Intention card. The "AI authors
 * data" half — it performs no persistence and codes only against the
 * CoachService interface, so the LLM vendor stays swappable.
 *
 * A malformed card is dropped (the reply still returns); only a missing reply
 * is fatal — chat must stay usable.
 */
final readonly class ChatCoach
{
    public function __construct(private CoachService $coach) {}

    /**
     * @param  list<array{role?: string, content?: string}>  $history  Prior turns, oldest first.
     * @param  array<string, mixed>  $context  Optional extra signal for the prompt.
     *
     * @throws CoachException
     * @throws ChatException
     */
    public function respond(string $message, array $history = [], array $context = []): ChatReply
    {
        $prompt = CoachPrompts::chat();

        $messages = [...$this->history($history), Message::user($this->userPrompt($message, $context))];

        $response = $this->coach->chat(new CoachRequest(
            messages: $messages,
            system: $prompt->system,
            temperature: 0.6,
            json: true,
            metadata: ['purpose' => 'chat', 'prompt_version' => $prompt->version],
        ));

        $envelope = ChatReplySchema::validate($response->json());

        $intention = null;

        if (isset($envelope['intention']) && is_array($envelope['intention'])) {
            try {
                $intention = AuthoredIntention::fromResponse($envelope['intention'], $response, $prompt->version);
            } catch (IntentionAuthoringException) {
                // A bad card must not break the conversation — drop it.
                $intention = null;
            }
        }

        return new ChatReply((string) $envelope['reply'], $intention);
    }

    /**
     * @param  list<array{role?: string, content?: string}>  $history
     * @return list<Message>
     */
    private function history(array $history): array
    {
        $messages = [];

        foreach ($history as $turn) {
            $content = (string) ($turn['content'] ?? '');

            if ($content === '') {
                continue;
            }

            $role = ($turn['role'] ?? 'user') === Role::Assistant->value
                ? Role::Assistant
                : Role::User;

            $messages[] = new Message($role, $content);
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(string $message, array $context): string
    {
        $prompt = trim($message);

        if ($context !== []) {
            $prompt .= "\n\nContext:\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        return $prompt;
    }
}
