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

        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';
        $type = is_string($data['type'] ?? null) ? trim($data['type']) : '';
        $cue = is_string($data['cue'] ?? null) ? trim($data['cue']) : '';
        $craving = is_string($data['craving'] ?? null) ? trim($data['craving']) : '';
        $response = is_string($data['response'] ?? null) ? trim($data['response']) : '';
        $reward = is_string($data['reward'] ?? null) ? trim($data['reward']) : '';

        if (
            $title === '' ||
            $type === '' || ! in_array($type, $validTypes, true) ||
            $cue === '' ||
            $craving === '' ||
            $response === '' ||
            $reward === ''
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

            $interventionPoint = is_string($strategyData['intervention_point'] ?? null) ? trim($strategyData['intervention_point']) : '';
            $approach = is_string($strategyData['approach'] ?? null) ? trim($strategyData['approach']) : '';

            if (
                $interventionPoint === '' ||
                ! in_array($interventionPoint, $validPoints, true) ||
                $approach === ''
            ) {
                throw CoachException::emptyResponse('intention-author');
            }

            $authoredStrategy = new AuthoredStrategy(
                interventionPoint: $interventionPoint,
                approach: $approach,
                rationale: isset($strategyData['rationale']) ? trim((string) $strategyData['rationale']) : null,
                promptVersion: IntentionAuthor::PROMPT_VERSION,
            );
        }

        $authored = new AuthoredIntention(
            title: $title,
            description: isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
            type: $type,
            cue: $cue,
            craving: $craving,
            response: $response,
            reward: $reward,
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
