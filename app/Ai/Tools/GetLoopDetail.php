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
 * Read-only tool: returns one loop's full anatomy, active strategy, and the 10
 * most recent outcome logs. Ownership-checked — a foreign or missing id returns
 * the string 'Loop not found.' without leaking existence.
 */
class GetLoopDetail implements Tool
{
    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    public function description(): Stringable|string
    {
        return "Get one loop's full anatomy, active strategy, and recent outcome logs.";
    }

    public function handle(Request $request): Stringable|string
    {
        $user = $this->auth->guard()->user();

        if (! $user instanceof User) {
            return 'Loop not found.';
        }

        $id = $request->integer('intention_id');

        if ($id === 0) {
            return 'Loop not found.';
        }

        $intention = Intention::where('id', $id)
            ->where('user_id', $user->id)
            ->with(['activeStrategy', 'actionLogs' => function ($query): void {
                $query->orderByDesc('logged_at')->limit(10);
            }])
            ->first();

        if ($intention === null) {
            return 'Loop not found.';
        }

        $strategy = $intention->activeStrategy;
        $logs = $intention->actionLogs->map(fn ($log): array => [
            'outcome' => $log->outcome,
            'reason' => $log->reason,
            'logged_at' => $log->logged_at?->toDateString(),
        ])->all();

        $payload = [
            'anatomy' => [
                'title' => $intention->title,
                'description' => $intention->description,
                'type' => $intention->type,
                'status' => $intention->status,
                'cue' => $intention->cue,
                'craving' => $intention->craving,
                'response' => $intention->response,
                'reward' => $intention->reward,
            ],
            'active_strategy' => $strategy === null ? null : [
                'version' => $strategy->version,
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'rationale' => $strategy->rationale,
            ],
            'recent_logs' => $logs,
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
