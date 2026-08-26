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
#[Description(<<<'TEXT'
Two scopes for one loop. `current_version` is how the active experiment is
going on its own evidence — read this to judge whether a strategy is working.
`lifetime` is the whole record across every version.

Occasions that never happened (skipped) are excluded from both completion
rates and reported as their own count, so a thin sample stays visible. A streak
is a statistic, not a reward.
TEXT)]
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
            'current_version' => $progress->forCurrentVersion($loop),
            'lifetime' => $progress->forLoop($loop),
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
