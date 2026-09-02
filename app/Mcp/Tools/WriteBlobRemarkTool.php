<?php

namespace App\Mcp\Tools;

use App\Actions\WriteBlobRemark;
use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('write-blob-remark')]
#[Description(<<<'TEXT'
Give Blob something to say. One remark, in Blob's voice, shown on Blob's own
screen and nowhere else in the app.

Blob is the app's companion, not its scoreboard. It describes itself and what
it has been doing; it does not describe the user, and it never assesses them.

The rules the app cannot check, and you have to keep:

- Sentence case. One or two sentences.
- Never congratulating, and no second person keeping score. "Blob has taken to
  sitting near the door" — not "you have been consistent".
- About Blob, or about the work. Never about how well the user is doing.
- Never name anything Blob has not unlocked yet.

The rules the app does check: 280 characters, and no exclamation marks. A body
breaking either one comes back with the rule named, so correct it and call
again.

Attach a remark to a loop when it is about that loop's work — a remark on a
paused or archived loop stops being shown, which is what you want. Leave the
loop off for something general.

Remarks are append-only: there is no way to edit or remove one, and one written
today may be relayed months from now. Write nothing you would not want read
back later, and do not write one after every check-in — a line every time is
wallpaper within a week.
TEXT)]
class WriteBlobRemarkTool extends Tool
{
    /**
     * What the app can actually verify. Tone is not on this list and the app
     * will not pretend to check it — 280 is two sentences with room to breathe,
     * and short enough that a remark cannot become a paragraph of advice.
     */
    private const CAP = 280;

    public function handle(Request $request, WriteBlobRemark $write): Response
    {
        $validated = $request->validate([
            'body' => ['required', 'string'],
            'intention_id' => ['nullable', 'integer'],
        ]);

        /** @var string $body */
        $body = $validated['body'];

        if (trim($body) === '') {
            return Response::error('A remark cannot be blank.');
        }

        if (mb_strlen($body) > self::CAP) {
            return Response::error(
                'A remark is capped at '.self::CAP.' characters. This one is '.mb_strlen($body).'.',
            );
        }

        if (str_contains($body, '!')) {
            return Response::error('A remark cannot contain an exclamation mark. Rewrite it flatter.');
        }

        $loop = null;

        if (($validated['intention_id'] ?? null) !== null) {
            $loop = $request->user()->intentions()->find($validated['intention_id']);

            if (! $loop instanceof Intention) {
                return Response::error('Not found.');
            }
        }

        $remark = $write->handle(
            $request->user(),
            // Verbatim: no trim, no tidying. Surrounding whitespace and all.
            $body,
            $loop,
        );

        return Response::json([
            'remark_id' => $remark->id,
            'loop_id' => $loop?->id,
            'body' => $remark->body,
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'body' => $schema->string()
                ->description('What Blob says. Up to 280 characters, sentence case, no exclamation marks.')
                ->required(),
            'intention_id' => $schema->integer()
                ->description('The loop this remark is about, as returned by list-loops. Omit for a general remark.'),
        ];
    }
}
