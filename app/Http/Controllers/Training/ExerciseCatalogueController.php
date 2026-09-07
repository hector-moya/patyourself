<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Searching the exercise catalogue, for the routine editor's picker.
 *
 * The catalogue is 876 imported rows plus whatever the user has added, so it
 * cannot simply be a prop on the loop screen — this is the seam the picker
 * queries as the user types. JSON rather than an Inertia page: it feeds a
 * control on a screen the user is already on, and a page visit would be the
 * wrong shape entirely.
 *
 * Scoped by {@see Exercise::availableTo()}, so the shared catalogue and the
 * user's own additions are searchable and nobody else's are. That is the same
 * rule the routine and set writers apply, and it is the whole authorization
 * story here — there is nothing else on these rows to protect.
 *
 * The term is bound as a plain `like` value rather than assembled into a raw
 * fragment. `IntentionController::matchTitleOrChain` needs an `ESCAPE` clause
 * because it escapes `%` and `_` so a `%` in a loop's title stays a literal
 * one; this does not escape them, so it needs no `ESCAPE` clause and cannot
 * reproduce the MySQL quoting bug that one records. The cost is that a `%`
 * typed into the picker acts as a wildcard, which in a search box is not worth
 * raw SQL to prevent.
 */
class ExerciseCatalogueController extends Controller
{
    /**
     * Enough to choose from without the picker becoming a scroll of its own.
     * The catalogue holds several hundred rows matching a term as short as
     * "press", and an unbounded response would be both slow and useless.
     */
    private const LIMIT = 20;

    public function index(Request $request): JsonResponse
    {
        // Only a genuine string is a search term. A hand-edited `?q[]=x` hands
        // `query()` an array, and `(string) $array` raises a warning Laravel's
        // handler turns into a 500 — the same tolerance `IntentionController`
        // and `ExportController` already apply to their own query parameters.
        $rawQuery = $request->query('q', '');
        $term = is_string($rawQuery) ? trim($rawQuery) : '';

        $exercises = Exercise::query()
            ->availableTo($request->user())
            ->when(
                $term !== '',
                fn (Builder $query) => $query->where('name', 'like', '%'.$term.'%'),
            )
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get(['id', 'name', 'category', 'equipment']);

        return response()->json([
            'exercises' => $exercises
                ->map(fn (Exercise $exercise): array => [
                    'id' => $exercise->id,
                    'name' => $exercise->name,
                    // Two things in this catalogue are called "Bench Press"
                    // and differ only by equipment, so the picker needs this
                    // to be choosable at all.
                    'category' => $exercise->category,
                    'equipment' => $exercise->equipment,
                ])
                ->values()
                ->all(),
        ]);
    }
}
