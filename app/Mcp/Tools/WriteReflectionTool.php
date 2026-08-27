<?php

namespace App\Mcp\Tools;

use App\Actions\WriteReflection;
use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write-reflection')]
#[Description(<<<'TEXT'
Write the loop's rolling narrative: one synthesis of what the record now shows.
Read loop-outcomes and loop-progress first — this is where you say what the
evidence adds up to, in prose, and it replaces what you wrote last time as the
loop's current reading.

Not the same as log-note. A note is one discrete thing the user observed. This
is the whole picture as it currently stands, and there is only ever one current
one.

Write about the strategy, never about the user: what the intervention is doing
and where it stops holding, not discipline or willpower or motivation. Never
propose a numeric target, and never congratulate — a streak is a statistic.

Say what the record does not show, too. If the evidence is thin, or the pattern
you thought you saw does not survive the reasons, write that. A narrative that
invents a trend to sound useful is worse than one that reports an open question.

You supply the words only. The window it covers and how many occasions sit
inside it are taken from the record.
TEXT)]
class WriteReflectionTool extends Tool
{
    public function handle(Request $request, WriteReflection $write): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            'content' => ['required', 'string', 'min:1', 'max:5000'],
        ]);

        // `min:1` does not trim, so a whitespace-only narrative slips past it.
        if (trim($validated['content']) === '') {
            return Response::error('A reflection needs something written in it.');
        }

        $loop = $request->user()->intentions()->with('activeStrategy')->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $summary = $write->handle($loop, $validated['content']);

        return Response::json([
            'loop_id' => $loop->id,
            // Verbatim, as written.
            'content' => $summary->content,
            'window_start' => $summary->window_start?->toIso8601String(),
            'window_end' => $summary->window_end?->toIso8601String(),
            'events_count' => $summary->events_count,
            'written_at' => $summary->created_at->toIso8601String(),
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
            'content' => $schema->string()
                ->description('The narrative itself, in prose. Recorded verbatim and shown on the loop\'s progress screen.')
                ->required(),
        ];
    }
}
