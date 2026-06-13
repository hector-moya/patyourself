<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateIntention;
use App\Actions\DeleteIntention;
use App\Actions\UpdateIntention;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIntentionRequest;
use App\Http\Requests\UpdateIntentionRequest;
use App\Http\Resources\IntentionResource;
use App\Models\Intention;
use App\Policies\IntentionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * JSON CRUD for intentions/loops. Thin: validation lives in form requests,
 * authorization in {@see IntentionPolicy}, and every write goes
 * through the shared Actions — the same ones the web controller uses.
 */
class IntentionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $intentions = $request->user()->intentions()
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->latest()
            ->get();

        return IntentionResource::collection($intentions);
    }

    public function store(StoreIntentionRequest $request, CreateIntention $create): JsonResponse
    {
        $intention = $create->handle($request->user(), $request->validated());

        return (new IntentionResource($intention))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Intention $intention): IntentionResource
    {
        Gate::authorize('view', $intention);

        return new IntentionResource($intention->load(['activeStrategy', 'activeAction']));
    }

    public function update(UpdateIntentionRequest $request, Intention $intention, UpdateIntention $update): IntentionResource
    {
        Gate::authorize('update', $intention);

        return new IntentionResource($update->handle($intention, $request->validated()));
    }

    public function destroy(Intention $intention, DeleteIntention $delete): Response
    {
        Gate::authorize('delete', $intention);

        $delete->handle($intention);

        return response()->noContent();
    }
}
