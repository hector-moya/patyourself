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

workflow says what this loop records on top of whether it happened. Almost
every loop records nothing extra, which is the default and needs no call. Set
it to "gym" for a training loop and each of its actions gains a routine —
which exercises, for how many sets and reps — that you can then build with
add-routine-exercise. Pass an empty string to go back to recording nothing
extra; everything already written is kept either way.
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
            // Validated against the registry rather than accepted as a tag.
            // The free-text version of this failed silently on "Gym" or a
            // trailing space, which is the whole reason the column is spelled
            // by config — so an unknown name is a refusal, not a write. The
            // empty string is allowed through as "nothing extra" and becomes
            // null below; it is what the web picker's first option submits.
            'workflow' => ['nullable', 'string', Rule::in([...array_keys(self::workflows()), ''])],
        ]);

        $fields = array_filter(
            array_intersect_key($validated, array_flip(['title', 'description', 'status', ...self::CHAIN_FIELDS])),
            static fn ($value): bool => $value !== null,
        );

        // Handled apart from the filter above because '' is a meaningful value
        // here and null is not: "" clears the workflow, absent leaves it be.
        if (array_key_exists('workflow', $validated) && $validated['workflow'] !== null) {
            $fields['workflow'] = $validated['workflow'] === '' ? null : $validated['workflow'];
        }

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
            'workflow' => $fresh->workflow,
            'loop' => [
                'cue' => $fresh->cue,
                'craving' => $fresh->craving,
                'response' => $fresh->response,
                'reward' => $fresh->reward,
            ],
        ]);
    }

    /**
     * The registry, keyed by the name stored on the loop.
     *
     * Read from config rather than listed here, so adding a module to
     * `config/workflows.php` makes it settable through the connector without
     * touching this tool — the same property the web picker has.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function workflows(): array
    {
        /** @var array<string, array<string, mixed>> $registry */
        $registry = config('workflows.registry', []);

        return $registry;
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
            'workflow' => $schema->string()
                ->enum([...array_keys(self::workflows()), ''])
                ->description(
                    'What this loop records beyond whether it happened. "gym" gives every '
                    .'action a routine of exercises, sets and reps. An empty string records '
                    .'nothing extra, which is the default and what almost every loop wants.'
                ),
        ];
    }
}
