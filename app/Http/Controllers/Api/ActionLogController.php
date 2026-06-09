<?php

namespace App\Http\Controllers\Api;

use App\Actions\LogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\LogActionRequest;
use App\Http\Resources\ActionLogResource;
use App\Models\Action;
use App\Policies\ActionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * JSON endpoint for logging an action's outcome. Thin: validation in the form
 * request, ownership in {@see ActionPolicy}, recording in the
 * shared {@see LogAction} — the same Action the web side uses.
 */
class ActionLogController extends Controller
{
    public function store(LogActionRequest $request, Action $action, LogAction $log): JsonResponse
    {
        Gate::authorize('log', $action);

        $entry = $log->handle($request->user(), $action, $request->validated());

        return (new ActionLogResource($entry))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
