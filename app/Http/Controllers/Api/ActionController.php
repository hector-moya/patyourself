<?php

namespace App\Http\Controllers\Api;

use App\Actions\RescheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RescheduleActionRequest;
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

        // A schedule that actually changed has just deleted every unlogged
        // slot ahead of now, so the grid this reply reads is empty until it is
        // rebuilt. An unchanged schedule (RescheduleAction's guard) purges
        // nothing, so there is nothing to rebuild. Materialising is idempotent
        // either way and never touches a logged occasion.
        $materialise->forLoop($action->intention);

        return response()->json([
            'id' => $action->id,
            'next_occurrence_at' => $action->nextOccurrenceAt(),
            'recurrence' => $action->recurrence,
            'status' => $action->status,
        ]);
    }
}
