<?php

namespace App\Mcp\Tools;

use App\Actions\Training\RemoveRoutineExercise;
use App\Models\Action;
use App\Models\ActionExercise;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('remove-routine-exercise')]
#[Description(<<<'TEXT'
Take an exercise off a gym action's routine. The remaining exercises close up
behind it, keeping their order.

This changes what the session is meant to contain from now on. Sets already
performed against that exercise are kept — a routine is a prescription, and
removing it does not remove the history of doing it.

Use the action_exercise_id from get-loop, not the exercise_id: the same
exercise can legitimately appear on more than one action.
TEXT)]
class RemoveRoutineExerciseTool extends Tool
{
    public function handle(Request $request, RemoveRoutineExercise $remove): Response
    {
        $validated = $request->validate([
            'action_id' => ['required', 'integer'],
            'action_exercise_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        $action = Action::query()
            ->whereHas('intention', fn ($query) => $query->where('user_id', $user->id))
            ->find($validated['action_id']);

        if (! $action instanceof Action) {
            return Response::error('Not found.');
        }

        // Scoped to the action as well as the id, so a row id from another
        // action cannot be removed by naming an action the user does own.
        $row = ActionExercise::query()
            ->where('action_id', $action->id)
            ->find($validated['action_exercise_id']);

        if (! $row instanceof ActionExercise) {
            return Response::error('That exercise is not on this action\'s routine.');
        }

        $remove->handle($action, $row);

        return Response::json([
            'removed' => $validated['action_exercise_id'],
            'action_id' => $action->id,
            'routine' => ActionExercise::query()
                ->where('action_id', $action->id)
                ->with('exercise')
                ->orderBy('position')
                ->get()
                ->map(fn (ActionExercise $remaining): array => [
                    'action_exercise_id' => $remaining->id,
                    'position' => $remaining->position,
                    'exercise' => $remaining->exercise?->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_id' => $schema->integer()
                ->description('The action whose routine is changing, as returned by get-loop.')
                ->required(),
            'action_exercise_id' => $schema->integer()
                ->description('The routine row to remove, as returned by get-loop. Not the exercise_id.')
                ->required(),
        ];
    }
}
