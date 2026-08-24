<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use App\Models\Strategy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get-loop')]
#[Description('Get one habit loop in full: the cue -> craving -> response -> reward chain plus the versioned strategy timeline, including why each version was superseded.')]
class GetLoopTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
        ]);

        $loop = $request->user()->intentions()
            ->with('activeStrategy')
            ->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $strategies = $loop->strategies()->orderedByVersion()->get();

        return Response::json([
            'id' => $loop->id,
            'title' => $loop->title,
            'description' => $loop->description,
            'type' => $loop->type,
            'status' => $loop->status,
            'loop' => [
                'cue' => $loop->cue,
                'craving' => $loop->craving,
                'response' => $loop->response,
                'reward' => $loop->reward,
            ],
            'active_strategy_version' => $loop->activeStrategy?->version,
            'strategies' => $strategies->map(fn (Strategy $strategy): array => [
                'version' => $strategy->version,
                'status' => $strategy->status,
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'rationale' => $strategy->rationale,
                'change_reason' => $strategy->change_reason,
                'superseded_reason' => $strategy->superseded_reason,
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intention_id' => $schema->integer()
                ->description('The loop id, as returned by list-loops.')
                ->required(),
        ];
    }
}
