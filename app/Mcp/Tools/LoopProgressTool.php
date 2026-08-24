<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use App\Services\Progress\LoopProgress;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('loop-progress')]
#[Description('Progress for one habit loop: current streak on the active strategy, lifetime completion rate and totals, and the recent outcome strip.')]
class LoopProgressTool extends Tool
{
    public function handle(Request $request, LoopProgress $progress): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
        ]);

        $loop = $request->user()->intentions()
            ->with(['activeStrategy', 'actionLogs'])
            ->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        return Response::json([
            'loop_id' => $loop->id,
            'title' => $loop->title,
            ...$progress->forLoop($loop),
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
