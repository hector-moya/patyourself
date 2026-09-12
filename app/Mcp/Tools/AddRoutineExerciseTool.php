<?php

namespace App\Mcp\Tools;

use App\Actions\Training\AddRoutineExercise;
use App\Models\Action;
use App\Models\Exercise;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('add-routine-exercise')]
#[Description(<<<'TEXT'
Put an exercise on a gym action's routine: what that session is meant to
contain, and for how many sets and reps.

Exercises append in the order you add them, which is the order they will be
worked through. Find the exercise_id with search-exercises first, and the
action_id with get-loop.

The loop must be recording "gym" — set that with update-loop. target_reps is a
single number rather than a range: "10", not "8-12".
TEXT)]
class AddRoutineExerciseTool extends Tool
{
    public function handle(Request $request, AddRoutineExercise $add): Response
    {
        $validated = $request->validate([
            'action_id' => ['required', 'integer'],
            'exercise_id' => ['required', 'integer'],
            'target_sets' => ['required', 'integer', 'min:1', 'max:20'],
            'target_reps' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();

        // Resolved through the user's own loops rather than off the global
        // table, so one connector cannot write into another person's routine.
        $action = Action::query()
            ->whereHas('intention', fn ($query) => $query->where('user_id', $user->id))
            ->with('intention')
            ->find($validated['action_id']);

        if (! $action instanceof Action) {
            return Response::error('Not found.');
        }

        if ($action->intention->workflow !== 'gym') {
            return Response::error(
                'That loop is not recording gym sessions, so its actions have no routine to add to. '
                .'Set it with update-loop first, passing workflow "gym".'
            );
        }

        // The same catalogue scope the web request validates against: the
        // shared set plus the user's own. Without it a routine row could point
        // at someone else's private movement, or at nothing at all.
        $inCatalogue = Exercise::query()
            ->availableTo($user)
            ->whereKey($validated['exercise_id'])
            ->exists();

        if (! $inCatalogue) {
            return Response::error('That exercise is not in your catalogue. Find one with search-exercises.');
        }

        // Position is the action's to compute, not this tool's to pass: it
        // takes max(position) + 1 under a locking read, which is what stops two
        // quick adds racing each other into the unique index.
        $row = $add->handle(
            $action,
            $validated['exercise_id'],
            $validated['target_sets'],
            $validated['target_reps'],
        );

        return Response::json([
            'action_exercise_id' => $row->id,
            'action_id' => $action->id,
            'exercise_id' => $row->exercise_id,
            'position' => $row->position,
            'target_sets' => $row->target_sets,
            'target_reps' => $row->target_reps,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action_id' => $schema->integer()
                ->description('The action this exercise belongs to, as returned by get-loop.')
                ->required(),
            'exercise_id' => $schema->integer()
                ->description('The exercise, as returned by search-exercises.')
                ->required(),
            'target_sets' => $schema->integer()
                ->description('How many sets are intended, e.g. 4.')
                ->required(),
            'target_reps' => $schema->integer()
                ->description('How many reps per set are intended, as one number, e.g. 8.')
                ->required(),
        ];
    }
}
