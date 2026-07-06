<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the user\'s habit loops (intentions), newest first. Defaults to active loops; pass status "all" to include paused, archived and completed loops.')]
class ListLoopsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in([...Intention::STATUSES, 'all'])],
        ]);

        $status = $validated['status'] ?? Intention::STATUS_ACTIVE;

        $loops = $request->user()->intentions()
            ->with('activeStrategy')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        return Response::json($loops->map(fn (Intention $loop): array => [
            'id' => $loop->id,
            'title' => $loop->title,
            'type' => $loop->type,
            'status' => $loop->status,
            'active_strategy_version' => $loop->activeStrategy?->version,
        ])->values()->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum([...Intention::STATUSES, 'all'])
                ->description('Filter by loop status. Omit for active loops only.'),
        ];
    }
}
