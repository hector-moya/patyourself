<?php

namespace App\Mcp\Tools;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Record the outcome of an action: completed, failed, or skipped. A failed outcome MUST include the user\'s stated reason — ask them why before calling this. Recurring actions automatically roll forward to their next occurrence.')]
class LogActionOutcomeTool extends Tool
{
    /**
     * The one write this server performs; it goes through the shared LogAction
     * so every invariant (immutable log, recurrence roll-forward, cue-answered
     * marking) holds — the same path the web and mobile API use.
     */
    public function handle(Request $request, LogAction $log): Response
    {
        $validated = $request->validate([
            'action_id' => ['required', 'integer'],
            'outcome' => ['required', 'string', Rule::in(ActionLog::OUTCOMES)],
            'reason' => [
                Rule::requiredIf(fn () => $request->get('outcome') === ActionLog::OUTCOME_FAILED),
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $action = Action::query()
            ->whereKey($validated['action_id'])
            ->whereHas('intention', fn (Builder $query) => $query
                ->where('user_id', $request->user()->id))
            ->first();

        if (! $action instanceof Action) {
            return Response::error('Not found.');
        }

        $entry = $log->handle($request->user(), $action, Arr::only($validated, ['outcome', 'reason']));

        return Response::json([
            'log_id' => $entry->id,
            'outcome' => $entry->outcome,
            'reason' => $entry->reason,
            'logged_at' => $entry->logged_at?->toIso8601String(),
            'action_status' => $action->fresh()->status,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_id' => $schema->integer()
                ->description('The action id, as returned by today-actions.')
                ->required(),
            'outcome' => $schema->string()
                ->enum(ActionLog::OUTCOMES)
                ->description('completed, failed, or skipped.')
                ->required(),
            'reason' => $schema->string()
                ->description('The user\'s stated reason. Required when the outcome is failed.'),
        ];
    }
}
