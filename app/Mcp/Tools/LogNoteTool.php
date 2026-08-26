<?php

namespace App\Mcp\Tools;

use App\Actions\LogNote;
use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('log-note')]
#[Description(<<<'TEXT'
Record an observation about a loop that is not an outcome — something the user
noticed between check-ins. "Worse on the days I skip lunch", "barely think
about it when someone else serves".

Use the user's own words, unchanged. Notes come back on get-loop and sit
alongside the outcomes on the loop's record, so they are read again when the
next experiment is written.
TEXT)]
class LogNoteTool extends Tool
{
    public function handle(Request $request, LogNote $log): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            'note' => ['required', 'string', 'max:2000'],
            'noted_at' => ['nullable', 'date'],
        ]);

        if (trim($validated['note']) === '') {
            return Response::error('A note cannot be blank.');
        }

        $loop = $request->user()->intentions()->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $note = $log->handle(
            $loop,
            // Verbatim: no trim, no tidying. The user's own words.
            $validated['note'],
            isset($validated['noted_at']) ? Date::parse($validated['noted_at']) : null,
        );

        return Response::json([
            'note_id' => $note->id,
            'loop_id' => $loop->id,
            'loop_title' => $loop->title,
            'body' => $note->body,
            'noted_at' => $note->noted_at->toIso8601String(),
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
            'note' => $schema->string()
                ->description('The observation, in the user\'s own words, passed through unchanged.')
                ->required(),
            'noted_at' => $schema->string()
                ->description('ISO-8601 datetime the observation was made. Defaults to now.'),
        ];
    }
}
