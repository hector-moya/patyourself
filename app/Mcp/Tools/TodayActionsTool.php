<?php

namespace App\Mcp\Tools;

use App\Models\Action;
use App\Services\Scheduling\TodaysActions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('today-actions')]
#[Description('List the actions the user should act on today: fired ("due_now"), scheduled later today ("upcoming"), plus unscheduled cue-anchored ones. Only actions on active loops.')]
class TodayActionsTool extends Tool
{
    public function handle(Request $request, TodaysActions $todaysActions): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? config('app.timezone');

        $actions = $todaysActions->for($user);

        return Response::json($actions->map(fn (Action $action): array => [
            'id' => $action->id,
            'loop_id' => $action->intention_id,
            'loop_title' => $action->intention->title,
            'title' => $action->title,
            'description' => $action->description,
            'status' => $action->status,
            'due' => $action->status === Action::STATUS_ACTIVE ? 'due_now' : 'upcoming',
            'scheduled_for' => $action->scheduled_for?->timezone($timezone)->toIso8601String(),
            'recurrence' => $action->recurrence,
        ])->values()->all());
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
