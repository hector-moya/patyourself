<?php

namespace App\Mcp\Tools;

use App\Actions\UpdateIntention;
use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('update-loop')]
#[Description(<<<'TEXT'
Correct a loop. Pass only the fields you are changing.

The cue -> craving -> response -> reward chain as first written is a
hypothesis, and the craving is the part most often wrong: what the user thought
they wanted is rarely what the behaviour is actually paying them. When the
outcomes say otherwise, fix the chain here rather than working around it.

status moves a loop between active, paused and archived. Pausing stops its
schedule; activating it again re-arms any action left stranded in the past
rather than firing every missed slot at once.
TEXT)]
class UpdateLoopTool extends Tool
{
    /**
     * A loop is a behaviour under change, not a task — "completed" is the
     * finish-line framing the notebook avoids, so it is not offered here.
     *
     * @var list<string>
     */
    private const STATUSES = [
        Intention::STATUS_ACTIVE,
        Intention::STATUS_PAUSED,
        Intention::STATUS_ARCHIVED,
    ];

    /** @var list<string> */
    private const CHAIN_FIELDS = ['cue', 'craving', 'response', 'reward'];

    public function handle(Request $request, UpdateIntention $update): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cue' => ['nullable', 'string', 'max:2000'],
            'craving' => ['nullable', 'string', 'max:2000'],
            'response' => ['nullable', 'string', 'max:2000'],
            'reward' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);

        $fields = array_filter(
            array_intersect_key($validated, array_flip(['title', 'description', 'status', ...self::CHAIN_FIELDS])),
            static fn ($value): bool => $value !== null,
        );

        if ($fields === []) {
            return Response::error('Pass at least one field to change.');
        }

        foreach (self::CHAIN_FIELDS as $field) {
            if (isset($fields[$field]) && trim((string) $fields[$field]) === '') {
                return Response::error("{$field} cannot be blank — it describes the behaviour, so an empty one breaks the loop.");
            }
        }

        $loop = $request->user()->intentions()->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        // Routed through the shared writer so the paused -> active re-anchoring
        // is not reimplemented at a second boundary.
        $update->handle($loop, $fields);

        $fresh = $loop->fresh();

        return Response::json([
            'loop_id' => $fresh->id,
            'title' => $fresh->title,
            'description' => $fresh->description,
            'status' => $fresh->status,
            'loop' => [
                'cue' => $fresh->cue,
                'craving' => $fresh->craving,
                'response' => $fresh->response,
                'reward' => $fresh->reward,
            ],
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
            'title' => $schema->string()->description('What the user calls this loop.'),
            'description' => $schema->string()->description('Optional longer framing.'),
            'cue' => $schema->string()->description('What sets the behaviour off.'),
            'craving' => $schema->string()
                ->description('What the behaviour is actually paying them. The field most often wrong as first written.'),
            'response' => $schema->string()->description('The behaviour itself.'),
            'reward' => $schema->string()->description('What they get from it.'),
            'status' => $schema->string()
                ->enum(self::STATUSES)
                ->description('active, paused or archived.'),
        ];
    }
}
