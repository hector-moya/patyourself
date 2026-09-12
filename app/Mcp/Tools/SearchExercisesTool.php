<?php

namespace App\Mcp\Tools;

use App\Models\Exercise;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('search-exercises')]
#[Description(<<<'TEXT'
Find exercises in the catalogue by name, so one can be put on a gym action's
routine with add-routine-exercise.

The catalogue is several hundred imported movements plus anything the user has
added themselves, so it has to be searched rather than listed. Two things in it
are called "Bench Press" and differ only by equipment — pass the equipment and
category back to the user when the name alone does not settle which they meant.
TEXT)]
class SearchExercisesTool extends Tool
{
    /**
     * Enough to choose from without the answer becoming a list nobody reads.
     * The same limit the web picker uses, and for the same reason: a term as
     * short as "press" matches several hundred rows.
     */
    private const LIMIT = 20;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:120'],
        ]);

        $term = trim((string) ($validated['query'] ?? ''));

        $user = $request->user();

        // The same scope the controllers apply: the shared catalogue plus this
        // user's own additions, and nobody else's. Their private movements are
        // as much theirs as their loops are.
        $available = Exercise::query()->availableTo($user);

        $exercises = $available->clone()
            ->when(
                $term !== '',
                fn (Builder $query) => $query->where('name', 'like', '%'.$term.'%'),
            )
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'category', 'equipment']);

        // An empty catalogue and a term that matches nothing are the same
        // answer from the outside — "nothing found" — and they need completely
        // different advice. Production ran with an unseeded catalogue, where
        // every search looked like a bad search term. Saying which it is costs
        // one count and stops that conversation happening again.
        $catalogueEmpty = $exercises->isEmpty() && $available->clone()->doesntExist();

        return Response::json([
            'exercises' => $exercises
                ->map(fn (Exercise $exercise): array => [
                    'exercise_id' => $exercise->id,
                    'name' => $exercise->name,
                    'category' => $exercise->category,
                    'equipment' => $exercise->equipment,
                ])
                ->values()
                ->all(),
            'catalogue_empty' => $catalogueEmpty,
            ...$catalogueEmpty ? [
                'note' => 'The exercise catalogue has no rows at all, so nothing can match. '
                    .'This is a setup problem rather than a search term: the catalogue import '
                    .'has not been run on this environment.',
            ] : [],
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description(
                    'Part of an exercise name, e.g. "bench press" or "squat". '
                    .'Omit it to see the start of the catalogue alphabetically.'
                ),
        ];
    }
}
