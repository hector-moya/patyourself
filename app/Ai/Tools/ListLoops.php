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
 * Read-only tool: returns all of the authenticated user's habit loops with their
 * current active strategies. Safe to call at any time — never throws, never leaks
 * another user's data.
 */
class ListLoops implements Tool
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function description(): Stringable|string
    {
        return "List the user's habit loops with their current strategies.";
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return 'Loop not found.';
        }

        $intentions = Intention::where('user_id', $user->id)
            ->with('activeStrategy')
            ->latest()
            ->get();

        if ($intentions->isEmpty()) {
            return 'No loops yet.';
        }

        $loops = $intentions->map(function (Intention $intention): array {
            $strategy = $intention->activeStrategy;

            $loop = [
                'id' => $intention->id,
                'title' => $intention->title,
                'type' => $intention->type,
                'status' => $intention->status,
                'cue' => $intention->cue,
                'response' => $intention->response,
            ];

            if ($strategy !== null) {
                $loop['active_strategy'] = [
                    'approach' => $strategy->approach,
                    'intervention_point' => $strategy->intervention_point,
                ];
            }

            return $loop;
        })->all();

        return (string) json_encode($loops, JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
