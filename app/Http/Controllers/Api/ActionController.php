<?php

namespace App\Http\Controllers\Api;

use App\Actions\RescheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RescheduleActionRequest;
use App\Models\Action;
use App\Services\Scheduling\MaterialiseOccurrences;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ActionController extends Controller
{
    public function update(
        RescheduleActionRequest $request,
        Action $action,
        RescheduleAction $reschedule,
        MaterialiseOccurrences $materialise,
    ): JsonResponse {
        Gate::authorize('update', $action);

        $action = $reschedule->handle(
            $action,
            $request->validated('kind'),
            $request->validated('time'),
            $request->validated('recurrence'),
            $request->validated('anchor'),
            $request->user()->timezone ?? (string) config('app.timezone'),
        );

        // The reschedule has just deleted every unlogged slot ahead of now, so
        // the grid this reply reads is empty until it is rebuilt. Materialising
        // is idempotent and never touches a logged occasion.
        $materialise->forLoop($action->intention);

        return response()->json([
            'id' => $action->id,
            'next_occurrence_at' => $action->nextOccurrenceAt(),
            'recurrence' => $action->recurrence,
            'status' => $action->status,
        ]);
    }
}
