<?php

namespace App\Mcp\Tools;

use App\Actions\ArchiveAction;
use App\Models\Action;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('remove-action')]
#[Description(<<<'TEXT'
Retire an action the user is no longer doing. It stops producing occasions and
stops appearing in what is due.

This archives rather than deletes, and every occasion and outcome it already
carries is kept — that history is the record. Say so if the user asks whether
anything is lost: nothing is.
TEXT)]
class RemoveActionTool extends Tool
{
    public function handle(Request $request, ArchiveAction $archive): Response
    {
        $validated = $request->validate([
            'action_id' => ['required', 'integer'],
        ]);

        $action = Action::query()
            ->whereKey($validated['action_id'])
            ->whereHas('intention', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->first();

        if (! $action instanceof Action) {
            return Response::error('Not found.');
        }

        $archived = $archive->handle($action);

        return Response::json([
            'action_id' => $archived->id,
            'loop_id' => $archived->intention_id,
            'title' => $archived->title,
            'status' => $archived->status,
            'occurrences_kept' => $archived->occurrences()->count(),
            'outcomes_kept' => $archived->logs()->count(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_id' => $schema->integer()
                ->description('The action id. It is archived, not deleted — its occasions and outcomes are kept.')
                ->required(),
        ];
    }
}
