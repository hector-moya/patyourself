<?php

namespace App\Http\Controllers;

use App\Models\Companion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets the user call their companion something else.
 *
 * The one thing about Blob the user simply states rather than earns or buys.
 * It costs nothing, it is not on the ladder and it is not on the skill list —
 * naming a creature is not an achievement.
 *
 * RENAMING IS NEVER FINAL AND NEVER DESTRUCTIVE. Clearing the field puts the
 * name back to "Blob" rather than leaving a companion with no name at all, so
 * there is no way to get this wrong and no state to be stuck in.
 *
 * Trimmed on the way in, and an all-whitespace name is stored as null so the
 * fallback in {@see Companion::displayName()} catches it either
 * way — belt and braces, because a name of three spaces would otherwise render
 * as a gap in every sentence Blob appears in.
 */
class CompanionNameController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // 24 is what fits the place bar and a ladder sentence without
            // wrapping. Nullable rather than required: clearing it is how you
            // go back to "Blob".
            'name' => ['nullable', 'string', 'max:24'],
        ]);

        $name = trim((string) ($validated['name'] ?? ''));

        $request->user()
            ->companion()
            ->firstOrCreate([])
            ->update(['name' => $name === '' ? null : $name]);

        return back();
    }
}
