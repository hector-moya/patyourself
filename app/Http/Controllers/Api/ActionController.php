<?php

namespace App\Http\Controllers\Api;

use App\Actions\RescheduleAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\RescheduleActionRequest;
use App\Models\Action;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ActionController extends Controller
{
    public function update(RescheduleActionRequest $request, Action $action, RescheduleAction $reschedule): JsonResponse
    {
        Gate::authorize('update', $action);

        $action = $reschedule->handle(
            $action,
            $request->validated('kind'),
            $request->validated('time'),
            $request->validated('recurrence'),
            $request->validated('anchor'),
            $request->user()->timezone ?? (string) config('app.timezone'),
        );

        return response()->json([
            'id' => $action->id,
            'scheduled_for' => $action->scheduled_for,
            'recurrence' => $action->recurrence,
            'status' => $action->status,
        ]);
    }
}
