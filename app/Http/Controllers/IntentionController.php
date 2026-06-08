<?php

namespace App\Http\Controllers;

use App\Actions\CreateIntention;
use App\Actions\DeleteIntention;
use App\Actions\UpdateIntention;
use App\Http\Requests\StoreIntentionRequest;
use App\Http\Requests\UpdateIntentionRequest;
use App\Models\Intention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The Inertia web side of loop CRUD — the write endpoints the list/detail
 * screens (Tasks 19–20) post to. Reads are served as Inertia props by those
 * screens and as JSON by {@see Api\IntentionController};
 * every write here funnels through the same shared Actions as the API, so the
 * two surfaces stay in lockstep.
 */
class IntentionController extends Controller
{
    public function store(StoreIntentionRequest $request, CreateIntention $create): RedirectResponse
    {
        $create->handle($request->user(), $request->validated());

        return back();
    }

    public function update(UpdateIntentionRequest $request, Intention $intention, UpdateIntention $update): RedirectResponse
    {
        Gate::authorize('update', $intention);

        $update->handle($intention, $request->validated());

        return back();
    }

    public function destroy(Intention $intention, DeleteIntention $delete): RedirectResponse
    {
        Gate::authorize('delete', $intention);

        $delete->handle($intention);

        return to_route('dashboard');
    }
}
