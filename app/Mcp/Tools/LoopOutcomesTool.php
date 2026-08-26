<?php

namespace App\Mcp\Tools;

use App\Models\ActionLog;
use App\Models\Intention;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Date;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('loop-outcomes')]
#[Description(<<<'TEXT'
Every outcome recorded on one loop, newest occasion first — with the user's
stated reason exactly as they said it, the context around it, and which
strategy version was running at the time.

This is the raw material the next experiment gets written from: read it before
proposing one, and look for where the chain is actually breaking rather than
how often it broke. loop-progress has the aggregates.
TEXT)]
class LoopOutcomesTool extends Tool
{
    private const LIMIT = 200;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            'since' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $timezone = $user->timezone ?? (string) config('app.timezone');

        $loop = $user->intentions()->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $since = isset($validated['since']) ? Date::parse($validated['since']) : null;

        // A log attributes to an experiment through actions.strategy_id, so it
        // always reports the version that was running when it was made.
        $logs = ActionLog::query()
            ->with(['occurrence', 'action.strategy'])
            ->whereHas('action', fn (Builder $query) => $query->where('intention_id', $loop->id))
            ->when($since !== null, fn (Builder $query) => $query
                ->whereHas('occurrence', fn (Builder $inner) => $inner->where('scheduled_for', '>=', $since)))
            ->get()
            ->sortByDesc(fn (ActionLog $log): string => $this->occurredAt($log)->toDateTimeString())
            ->take(self::LIMIT)
            ->values();

        return Response::json([
            'loop_id' => $loop->id,
            'title' => $loop->title,
            'since' => $since?->toIso8601String(),
            'count' => $logs->count(),
            'truncated' => $logs->count() === self::LIMIT,
            'outcomes' => $logs->map(fn (ActionLog $log): array => [
                'log_id' => $log->id,
                'occurrence_id' => $log->occurrence_id,
                'occurred_at' => $this->occurredAt($log)->timezone($timezone)->toIso8601String(),
                'logged_at' => $log->logged_at->timezone($timezone)->toIso8601String(),
                'action_id' => $log->action_id,
                'action_title' => $log->action->title,
                'outcome' => $log->outcome,
                // Verbatim, exactly as the user said it.
                'reason' => $log->reason,
                'context' => $log->context,
                'context_fields' => $log->context_fields,
                'strategy_version' => $log->action->strategy?->version,
                'intervention_point' => $log->action->strategy?->intervention_point,
            ])->all(),
        ]);
    }

    /**
     * When the occasion happened. Falls back to `logged_at` for a log written
     * before occurrences existed, which is all the old model recorded.
     */
    private function occurredAt(ActionLog $log): CarbonInterface
    {
        return $log->occurrence?->scheduled_for ?? $log->logged_at;
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'intention_id' => $schema->integer()
                ->description('The loop id, as returned by list-loops.')
                ->required(),
            'since' => $schema->string()
                ->description('ISO-8601 date or datetime. Filters by when the occasion happened, not by when it was logged.'),
        ];
    }
}
