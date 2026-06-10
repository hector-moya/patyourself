<?php

namespace App\Ai\Tools;

use App\Actions\AuthorIntention;
use App\Ai\Agents\IntentionAuthor;
use App\Ai\TurnCollector;
use App\Models\User;
use App\Services\Coach\Authoring\AuthoredIntention;
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

        $response = (new IntentionAuthor)->prompt((string) $request['goal']);

        $authored = AuthoredIntention::fromStructured(
            $response->structured,
            $response->meta->model ?? 'unknown',
            IntentionAuthor::PROMPT_VERSION,
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
