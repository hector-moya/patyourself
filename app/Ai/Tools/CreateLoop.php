<?php

namespace App\Ai\Tools;

use App\Actions\AuthorIntention;
use App\Ai\Agents\IntentionAuthor;
use App\Ai\TurnCollector;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Coach\Authoring\AuthoredIntention;
use App\Services\Coach\Authoring\AuthoredStrategy;
use App\Services\Coach\Exceptions\CoachException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The coach's loop-authoring tool. Prompts the IntentionAuthor specialist for a
 * structured loop, persists it through the AuthorIntention action (the only DB
 * writer), and registers the new id with the TurnCollector so the controller
 * can return the card. Returns a short confirmation the coach can speak to.
 */
class CreateLoop implements Tool
{
    public function __construct(
        private readonly AuthorIntention $author,
        private readonly TurnCollector $collector,
        private readonly AuthFactory $auth,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a habit loop for the user from a goal they have described. '
            .'Use when the user wants to start building or breaking a habit.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            throw CoachException::emptyResponse('intention-author');
        }

        $data = (new IntentionAuthor)->prompt((string) $request['goal'])->structured;

        // Guard: all required loop fields must be non-empty strings and type valid.
        $validTypes = [Intention::TYPE_BUILD, Intention::TYPE_BREAK];

        if (
            empty($data['title']) || ! is_string($data['title']) ||
            empty($data['type']) || ! is_string($data['type']) || ! in_array($data['type'], $validTypes, true) ||
            empty($data['cue']) || ! is_string($data['cue']) ||
            empty($data['craving']) || ! is_string($data['craving']) ||
            empty($data['response']) || ! is_string($data['response']) ||
            empty($data['reward']) || ! is_string($data['reward'])
        ) {
            throw CoachException::emptyResponse('intention-author');
        }

        // Build strategy DTO if present; invalid nested strategy → throw.
        $authoredStrategy = null;
        if (isset($data['strategy']) && is_array($data['strategy'])) {
            $strategyData = $data['strategy'];
            $validPoints = [
                Strategy::POINT_CUE,
                Strategy::POINT_CRAVING,
                Strategy::POINT_RESPONSE,
                Strategy::POINT_REWARD,
            ];

            if (
                empty($strategyData['intervention_point']) ||
                ! in_array($strategyData['intervention_point'], $validPoints, true) ||
                empty($strategyData['approach'])
            ) {
                throw CoachException::emptyResponse('intention-author');
            }

            $authoredStrategy = new AuthoredStrategy(
                interventionPoint: (string) $strategyData['intervention_point'],
                approach: (string) $strategyData['approach'],
                rationale: isset($strategyData['rationale']) ? (string) $strategyData['rationale'] : null,
                promptVersion: IntentionAuthor::PROMPT_VERSION,
            );
        }

        $authored = new AuthoredIntention(
            title: (string) $data['title'],
            description: isset($data['description']) && $data['description'] !== '' ? (string) $data['description'] : null,
            type: (string) $data['type'],
            cue: (string) $data['cue'],
            craving: (string) $data['craving'],
            response: (string) $data['response'],
            reward: (string) $data['reward'],
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
            tags: [],
            strategy: $authoredStrategy,
            model: 'claude-sonnet-4-6',
            promptVersion: IntentionAuthor::PROMPT_VERSION,
        );

        $intention = $this->author->handle($user, (string) $request['goal'], [], $authored);

        $this->collector->addIntention($intention->id);

        return "Created the loop \"{$intention->title}\" (id {$intention->id}). It is now visible to the user as a card.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()->required(),
        ];
    }
}
