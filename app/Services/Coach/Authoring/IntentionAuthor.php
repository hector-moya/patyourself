<?php

namespace App\Services\Coach\Authoring;

use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\Data\CoachRequest;
use App\Services\Coach\Data\Message;
use App\Services\Coach\Exceptions\CoachException;

/**
 * Turns a user's free-text habit goal into a validated, structured Intention.
 *
 * This is the "LLM -> structured JSON" core: it prompts the coach for the
 * Intention schema, asks for JSON, and validates the result. It performs no
 * persistence (the AuthorIntention action does) and codes only against the
 * CoachService interface, so the LLM vendor stays swappable.
 */
final readonly class IntentionAuthor
{
    public function __construct(private CoachService $coach) {}

    /**
     * @param  array<string, mixed>  $context  Optional extra signal for the prompt
     *                                         (e.g. prior loops) — caller-supplied.
     *
     * @throws CoachException On transport / unparseable output.
     * @throws IntentionAuthoringException When the JSON does not satisfy the schema.
     */
    public function author(string $goal, array $context = []): AuthoredIntention
    {
        $request = new CoachRequest(
            messages: [Message::user($this->userPrompt($goal, $context))],
            system: IntentionSchema::instructions(),
            // Lower temperature: authoring is a structuring task, not a creative one.
            temperature: 0.4,
            json: true,
            metadata: ['purpose' => 'intention_authoring'],
        );

        $response = $this->coach->chat($request);

        return AuthoredIntention::fromResponse($response->json(), $response);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function userPrompt(string $goal, array $context): string
    {
        $prompt = "The user wants help with this habit:\n\n".trim($goal);

        if ($context !== []) {
            $prompt .= "\n\nAdditional context:\n".json_encode(
                $context,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        }

        return $prompt;
    }
}
