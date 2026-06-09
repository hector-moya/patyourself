<?php

namespace App\Http\Controllers;

use App\Actions\CreateIntention;
use App\Actions\DeleteIntention;
use App\Actions\UpdateIntention;
use App\Http\Requests\StoreIntentionRequest;
use App\Http\Requests\UpdateIntentionRequest;
use App\Http\Resources\IntentionResource;
use App\Http\Resources\StrategyResource;
use App\Models\Intention;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Inertia web side of loops. Renders the loops-list and loop-detail
 * screens, and funnels every write through the same shared Actions the JSON
 * API uses, so the two surfaces stay in lockstep. The screen *content* is
 * fleshed out in Tasks 19–20; this controller hands those pages their props.
 */
class IntentionController extends Controller
{
    public function index(Request $request): Response
    {
        $intentions = $request->user()->intentions()
            ->with('activeStrategy')
            ->latest()
            ->get()
            // Surface the loops the user is actively working first; the rest
            // (paused / completed / archived) settle below, newest-first within.
            ->sortBy(fn (Intention $intention): int => $intention->status === Intention::STATUS_ACTIVE ? 0 : 1)
            ->values();

        return Inertia::render('intentions/index', [
            'intentions' => IntentionResource::collection($intentions)->resolve(),
        ]);
    }

    public function show(Intention $intention): Response
    {
        Gate::authorize('view', $intention);

        $intention->load('activeStrategy');
        $strategies = $intention->strategies()->orderedByVersion()->get();

        return Inertia::render('intentions/show', [
            'intention' => (new IntentionResource($intention))->resolve(),
            'strategies' => StrategyResource::collection($strategies)->resolve(),
        ]);
    }

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

        return to_route('intentions.index');
    }
}
