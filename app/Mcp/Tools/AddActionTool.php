<?php

namespace App\Mcp\Tools;

use App\Actions\CreateAction;
use App\Concerns\DescribesActionShape;
use App\Models\Intention;
use App\Services\Authoring\AuthoredAction;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('add-action')]
#[Description(<<<'TEXT'
Add an action to a loop's current experiment. Use this to split one action into
two — one per meal, say — so each occasion is logged on its own rather than
sharing a single row.

A "clock" action has a time and a recurrence and is scheduled, so it produces
occasions that can go unlogged and be caught up later. An "anchored" action
fires off a cue instead ("after serving the first plate"); it has no schedule,
so it never goes unlogged and is logged ad hoc against a datetime.
TEXT)]
class AddActionTool extends Tool
{
    use DescribesActionShape;

    public function handle(Request $request, CreateAction $create): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', 'string', Rule::in(self::KINDS)],
            'time' => ['nullable', 'string', 'required_if:kind,clock', 'regex:'.self::TIME_PATTERN],
            'recurrence' => ['nullable', 'string', Rule::in(self::RECURRENCES)],
            'anchor' => ['nullable', 'string', 'max:250', 'required_if:kind,anchored'],
        ]);

        $loop = $request->user()->intentions()->with('activeStrategy')->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        try {
            $action = $create->handle($loop, new AuthoredAction(
                title: $validated['title'],
                description: $validated['description'] ?? null,
                kind: $validated['kind'],
                time: $validated['time'] ?? null,
                recurrence: $validated['recurrence'] ?? 'once',
                anchor: $validated['anchor'] ?? null,
            ));
        } catch (StrategyTransitionException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json($this->describeAction($action, $loop));
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
            'title' => $schema->string()
                ->description('What the user actually does, in their language.')
                ->required(),
            'description' => $schema->string()
                ->description('Optional detail about how to do it.'),
            ...$this->scheduleSchema($schema, kindRequired: true),
        ];
    }
}
