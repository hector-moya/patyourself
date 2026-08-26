<?php

namespace App\Mcp\Tools;

use App\Services\Scheduling\TodaysOccasion;
use App\Services\Scheduling\TodaysOccasions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('today-actions')]
#[Description(<<<'TEXT'
What the user is working on today: occasions whose time has passed ("due_now"),
occasions later today ("upcoming"), and cue-anchored actions that have no clock
time at all ("anchored"). Only actions on active loops.

This is today's list, not a backlog. An occasion missed on an earlier day is
never listed here — it stays loggable forever and is reachable through
pending-outcomes, which is where a catch-up belongs.
TEXT)]
class TodayActionsTool extends Tool
{
    public function handle(Request $request, TodaysOccasions $todaysOccasions): Response
    {
        $user = $request->user();
        $timezone = $user->timezone ?? config('app.timezone');

        $occasions = $todaysOccasions->for($user);

        return Response::json($occasions->map(fn (TodaysOccasion $occasion): array => [
            'id' => $occasion->action->id,
            'occurrence_id' => $occasion->occurrence?->id,
            'loop_id' => $occasion->action->intention_id,
            'loop_title' => $occasion->action->intention->title,
            'title' => $occasion->action->title,
            'description' => $occasion->action->description,
            'due' => $occasion->due,
            'scheduled_for' => $occasion->scheduledFor?->timezone($timezone)->toIso8601String(),
            'recurrence' => $occasion->action->recurrence,
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
