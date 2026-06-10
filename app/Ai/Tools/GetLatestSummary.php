<?php

namespace App\Ai\Tools;

use App\Models\Intention;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Read-only tool: returns the latest rolling pattern summary for one loop.
 * Ownership-checked — a foreign or missing id returns 'Loop not found.' without
 * leaking existence.
 */
class GetLatestSummary implements Tool
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function description(): Stringable|string
    {
        return 'Get the latest rolling pattern summary for one loop.';
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return 'Loop not found.';
        }

        $intention = Intention::where('id', $request['intention_id'])
            ->where('user_id', $user->id)
            ->with('latestSummary')
            ->first();

        if ($intention === null) {
            return 'Loop not found.';
        }

        $summary = $intention->latestSummary;

        if ($summary === null) {
            return 'No summary yet for this loop.';
        }

        $payload = [
            'content' => $summary->content,
            'patterns' => $summary->metadata['patterns'] ?? [],
            'window_end' => $summary->window_end?->toDateString(),
            'events_count' => $summary->events_count,
        ];

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'intention_id' => $schema->integer()->required(),
        ];
    }
}
