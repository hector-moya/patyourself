<?php

namespace App\Mcp\Tools;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Note;
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
    private const NOTE_LIMIT = 50;

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

        // Counted in one query rather than per version: this is what tells a
        // version that failed from one that was never tested.
        $outcomeCounts = ActionLog::query()
            ->join('actions', 'actions.id', '=', 'action_logs.action_id')
            ->where('actions.intention_id', $loop->id)
            ->groupBy('actions.strategy_id')
            ->selectRaw('actions.strategy_id as strategy_id, count(*) as total')
            ->pluck('total', 'strategy_id');

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
            // Observations that belong to the loop but to no occasion. A note
            // nothing can read back would repeat the bug this phase fixed.
            'notes' => $loop->notes()->limit(self::NOTE_LIMIT)->get()
                ->map(fn (Note $note): array => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'noted_at' => $note->noted_at->toIso8601String(),
                ])->values()->all(),
            'strategies' => $strategies->map(fn (Strategy $strategy): array => [
                'version' => $strategy->version,
                'status' => $strategy->status,
                'intervention_point' => $strategy->intervention_point,
                'approach' => $strategy->approach,
                'rationale' => $strategy->rationale,
                'change_reason' => $strategy->change_reason,
                'superseded_reason' => $strategy->superseded_reason,
                'outcomes_recorded' => (int) ($outcomeCounts[$strategy->id] ?? 0),
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
