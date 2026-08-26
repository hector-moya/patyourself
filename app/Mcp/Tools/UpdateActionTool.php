<?php

namespace App\Mcp\Tools;

use App\Actions\RescheduleAction;
use App\Concerns\DescribesActionShape;
use App\Models\Action;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\JsonSchema\Types\Type as SchemaType;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-action')]
#[Description(<<<'TEXT'
Change an existing action: retitle it, or move when it happens. Pass only the
fields you are changing.

Changing the schedule re-anchors the action's series, so occasions from here on
follow the new cadence. Occasions already recorded are never touched — the
history stands.
TEXT)]
class UpdateActionTool extends Tool
{
    use DescribesActionShape;

    public function handle(Request $request, RescheduleAction $reschedule): Response
    {
        $validated = $request->validate([
            'action_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:250'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', Rule::in(self::KINDS)],
            'time' => ['nullable', 'string', 'required_if:kind,clock', 'regex:'.self::TIME_PATTERN],
            'recurrence' => ['nullable', 'string', Rule::in(self::RECURRENCES)],
            'anchor' => ['nullable', 'string', 'max:250', 'required_if:kind,anchored'],
        ]);

        $action = Action::query()
            ->with(['intention', 'strategy'])
            ->whereKey($validated['action_id'])
            ->whereHas('intention', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->first();

        if (! $action instanceof Action) {
            return Response::error('Not found.');
        }

        $fields = array_filter(
            [
                'title' => $validated['title'] ?? null,
                'description' => $validated['description'] ?? null,
            ],
            static fn ($value): bool => $value !== null,
        );

        if ($fields === [] && ! isset($validated['kind'])) {
            return Response::error('Pass at least one field to change.');
        }

        if ($fields !== []) {
            $action->update($fields);
        }

        if (isset($validated['kind'])) {
            // Routed through the shared reschedule writer so the series
            // re-anchoring lives in one place rather than at a second boundary.
            $action = $reschedule->handle(
                $action,
                $validated['kind'],
                $validated['time'] ?? null,
                $validated['recurrence'] ?? null,
                $validated['anchor'] ?? null,
                $request->user()->timezone ?? (string) config('app.timezone'),
            );
        }

        return Response::json($this->describeAction($action->fresh(), $action->intention));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        /** @var array<string, SchemaType> $scheduleSchema */
        $scheduleSchema = $this->scheduleSchema($schema, kindRequired: false);

        return [
            'action_id' => $schema->integer()
                ->description('The action id, as returned by add-action, today-actions or pending-outcomes.')
                ->required(),
            'title' => $schema->string()
                ->description('The new title, in the user\'s language.'),
            'description' => $schema->string()
                ->description('Optional detail about how to do it.'),
            ...$scheduleSchema,
        ];
    }
}
