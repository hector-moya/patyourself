<?php

namespace App\Http\Controllers;

use App\Actions\LogNote;
use App\Http\Requests\StoreNoteRequest;
use App\Models\Intention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Records an observation against a loop from the lab record.
 *
 * Notes already rendered on the record; until now the only writer was the
 * log-note MCP tool. There is deliberately no edit and no delete — a note you
 * wish you had not written is still what you thought at the time.
 */
class NoteController extends Controller
{
    public function store(StoreNoteRequest $request, Intention $intention, LogNote $logNote): RedirectResponse
    {
        Gate::authorize('update', $intention);

        // Verbatim: the raw input, not the trimmed value validation looked at.
        $logNote->handle($intention, (string) $request->input('body'));

        return back();
    }
}
