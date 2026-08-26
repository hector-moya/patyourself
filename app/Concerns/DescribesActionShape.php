<?php

namespace App\Concerns;

use App\Models\Action;
use App\Models\Intention;
use App\Services\Authoring\AuthoredAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The action shape shared by add-action and update-action: the vocabulary
 * {@see AuthoredAction} accepts, the schedule half of
 * the tool schema, and the response body. Shared so the two tools cannot
 * describe or validate the same fields differently.
 */
trait DescribesActionShape
{
    /** @var list<string> */
    public const KINDS = ['clock', 'anchored'];

    /** @var list<string> */
    public const RECURRENCES = ['once', 'daily', 'weekdays', 'weekly'];

    /** A 24-hour local time. Mirrors AuthoredAction's own guard. */
    public const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @return array<string, mixed>
     */
    protected function describeAction(Action $action, Intention $loop): array
    {
        return [
            'action_id' => $action->id,
            'loop_id' => $loop->id,
            'loop_title' => $loop->title,
            'title' => $action->title,
            'description' => $action->description,
            'kind' => $action->metadata['schedule_kind'] ?? null,
            'scheduled_for' => $action->scheduled_for?->toIso8601String(),
            'recurrence' => $action->recurrence,
            'anchor' => $action->metadata['anchor'] ?? null,
            'status' => $action->status,
            'strategy_version' => $action->strategy?->version,
        ];
    }

    /**
     * The schedule half of the tool schema.
     *
     * @return array<string, Type>
     */
    protected function scheduleSchema(JsonSchema $schema, bool $kindRequired): array
    {
        $kind = $schema->string()
            ->enum(self::KINDS)
            ->description('clock for a scheduled action, anchored for one that fires off a cue.');

        return [
            'kind' => $kindRequired ? $kind->required() : $kind,
            'time' => $schema->string()
                ->description('Local time as HH:MM. Required when kind is clock.'),
            'recurrence' => $schema->string()
                ->enum(self::RECURRENCES)
                ->description('How often a clock action repeats. Defaults to once.'),
            'anchor' => $schema->string()
                ->description('The cue phrase, e.g. "after serving the first plate". Required when kind is anchored.'),
        ];
    }
}
