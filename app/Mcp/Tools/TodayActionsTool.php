<?php

namespace App\Mcp\Tools;

use App\Models\Action;
use App\Models\Intention;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the actions the user should act on today: fired ("due_now"), scheduled later today ("upcoming"), plus unscheduled cue-anchored ones. Only actions on active loops.')]
class TodayActionsTool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? config('app.timezone');
        $endOfToday = Date::now($timezone)->endOfDay()->utc();

        $actions = Action::query()
            ->pending()
            ->whereHas('intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->where(fn (Builder $query) => $query
                ->whereNull('scheduled_for')
                ->orWhere('scheduled_for', '<=', $endOfToday))
            ->with('intention:id,title')
            ->orderBy('scheduled_for')
            ->get();

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
