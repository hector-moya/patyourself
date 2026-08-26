<?php

namespace App\Mcp\Tools;

use App\Models\Intention;
use App\Models\Occurrence;
use App\Services\Scheduling\MaterialiseOccurrences;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('pending-outcomes')]
#[Description(<<<'TEXT'
Occasions that have already passed and carry no outcome yet — what to ask the
user about when a check-in opens. Newest first, defaulting to the last 14 days
so a long gap does not turn the conversation into an audit.

Nothing here is overdue and nothing expires: an occasion stays loggable
forever. Pass an older `since` when the user wants to go further back. Do not
present this list as debt, and do not count it back at them.
TEXT)]
class PendingOutcomesTool extends Tool
{
    /** A check-in opens on the recent window, not on the whole backlog. */
    private const DEFAULT_WINDOW_DAYS = 14;

    private const LIMIT = 100;

    public function handle(Request $request, MaterialiseOccurrences $materialise): Response
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $timezone = $user->timezone ?? (string) config('app.timezone');

        $since = isset($validated['since'])
            ? Date::parse($validated['since'])
            : Date::now()->subDays(self::DEFAULT_WINDOW_DAYS);

        // Lazy materialisation: a read is the only thing that creates occasions,
        // so this is where one that was never logged first appears.
        $materialise->forUser($user);

        $occurrences = Occurrence::query()
            ->unlogged()
            ->with('action.intention:id,title')
            ->where('scheduled_for', '<=', Date::now())
            ->where('scheduled_for', '>=', $since)
            ->whereHas('action.intention', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('status', Intention::STATUS_ACTIVE))
            ->orderByDesc('scheduled_for')
            ->limit(self::LIMIT + 1)
            ->get();

        $truncated = $occurrences->count() > self::LIMIT;

        return Response::json([
            'since' => $since->toIso8601String(),
            'count' => min($occurrences->count(), self::LIMIT),
            'truncated' => $truncated,
            'occurrences' => $occurrences->take(self::LIMIT)->map(fn (Occurrence $occurrence): array => [
                'occurrence_id' => $occurrence->id,
                'loop_id' => $occurrence->action->intention_id,
                'loop_title' => $occurrence->action->intention->title,
                'action_id' => $occurrence->action_id,
                'action_title' => $occurrence->action->title,
                'scheduled_for' => $occurrence->scheduled_for->timezone($timezone)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->string()
                ->description('ISO-8601 date or datetime. Defaults to 14 days ago. Older occasions are never discarded — pass an earlier date to reach them.'),
        ];
    }
}
