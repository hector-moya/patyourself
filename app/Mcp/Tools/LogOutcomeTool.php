<?php

namespace App\Mcp\Tools;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Occurrence;
use App\Services\Companion\CompanionAnnouncement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('log-outcome')]
#[Description(<<<'TEXT'
Record what happened on one occasion: completed, failed, or skipped. Name an
occurrence_id from pending-outcomes to log it — including one from days ago,
which is how a catch-up works. For an unscheduled, cue-anchored action pass
action_id plus occurred_at instead.

skipped means the occasion never happened at all (no meal, travelling, ill).
If the occasion happened and the strategy did not hold — including simply not
thinking about it — that is failed, and it MUST carry the user's stated reason.
Ask them why, and pass their own words through unchanged.
TEXT)]
class LogOutcomeTool extends Tool
{
    /**
     * The structured context set, deliberately short so the free text stays the
     * primary record. Closed: anything else is rejected rather than stored.
     *
     * @var list<string>
     */
    private const CONTEXT_FIELDS = ['place', 'with_others', 'preceded_by'];

    /**
     * Goes through the shared {@see LogAction} so every invariant — immutable
     * log, one outcome per occasion, the action row never written, cue-answered
     * marking — holds exactly as it does for the web surface.
     *
     * There is no next-due cursor to roll: an outcome attaches to the occasion
     * it describes and nothing else moves.
     */
    public function handle(Request $request, LogAction $log, CompanionAnnouncement $companion): Response
    {
        $validated = $request->validate([
            'occurrence_id' => ['nullable', 'integer', 'required_without:action_id'],
            'action_id' => ['nullable', 'integer', 'required_without:occurrence_id'],
            'occurred_at' => ['nullable', 'date', 'required_with:action_id'],
            'outcome' => ['required', 'string', Rule::in(ActionLog::OUTCOMES)],
            'reason' => [
                Rule::requiredIf(fn (): bool => $request->get('outcome') === ActionLog::OUTCOME_FAILED),
                'nullable',
                'string',
                'max:2000',
            ],
            'context' => ['nullable', 'string', 'max:2000'],
            'context_fields' => ['nullable', 'array'],
            'context_fields.place' => ['nullable', 'string', 'max:120'],
            'context_fields.with_others' => ['nullable', 'boolean'],
            'context_fields.preceded_by' => ['nullable', 'string', 'max:200'],
        ]);

        if (isset($validated['occurrence_id'], $validated['action_id'])) {
            return Response::error('Pass either occurrence_id or action_id, not both.');
        }

        // Read the raw input, not the validated set: validate() strips sub-keys
        // it has no rule for, so an unknown field would silently disappear
        // instead of being refused.
        $submitted = $request->get('context_fields');
        $unknown = array_diff(array_keys(is_array($submitted) ? $submitted : []), self::CONTEXT_FIELDS);

        if ($unknown !== []) {
            return Response::error(
                'Unknown context field(s): '.implode(', ', $unknown)
                .'. Allowed: '.implode(', ', self::CONTEXT_FIELDS).'.',
            );
        }

        $occurrence = isset($validated['occurrence_id'])
            ? $this->ownedOccurrence($request, (int) $validated['occurrence_id'])
            : $this->adHocOccurrence($request, (int) $validated['action_id'], (string) $validated['occurred_at']);

        if (! $occurrence instanceof Occurrence) {
            return Response::error('Not found.');
        }

        if ($occurrence->isLogged()) {
            return Response::error('That occasion already has an outcome.');
        }

        $action = $occurrence->action;

        // Read before the write, so the comparison afterwards is against where
        // Blob actually stood rather than where it stands now.
        $stageBefore = $companion->stageFor($request->user());

        $entry = $log->handle(
            $request->user(),
            $action,
            Arr::only($validated, ['outcome', 'reason', 'context', 'context_fields']),
            $occurrence,
        );

        return Response::json([
            'log_id' => $entry->id,
            'occurrence_id' => $occurrence->id,
            'occurred_at' => $occurrence->scheduled_for->toIso8601String(),
            'outcome' => $entry->outcome,
            'reason' => $entry->reason,
            'context' => $entry->context,
            'context_fields' => $entry->context_fields,
            'loop_id' => $action->intention_id,
            'loop_title' => $action->intention->title,
            'action_title' => $action->title,
            // Present only when this outcome moved Blob up a stage, and then
            // the message is relayed exactly as written. Composing praise here
            // would put the app's one warm voice in the model's hands.
            ...$companion->since($request->user(), $stageBefore),
        ]);
    }

    private function ownedOccurrence(Request $request, int $id): ?Occurrence
    {
        return Occurrence::query()
            ->with('action.intention')
            ->whereKey($id)
            ->whereHas('action.intention', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->first();
    }

    /**
     * A cue-anchored action has no scheduled time, so it can never go unlogged —
     * it is logged ad hoc against the datetime the user gives, and the occasion
     * is created at that moment.
     */
    private function adHocOccurrence(Request $request, int $actionId, string $occurredAt): ?Occurrence
    {
        $action = Action::query()
            ->with('intention')
            ->whereKey($actionId)
            ->whereHas('intention', fn (Builder $query) => $query->where('user_id', $request->user()->id))
            ->first();

        if (! $action instanceof Action) {
            return null;
        }

        $occurrence = Occurrence::query()->firstOrCreate([
            'action_id' => $action->id,
            'scheduled_for' => Date::parse($occurredAt),
        ]);

        return $occurrence->setRelation('action', $action);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'occurrence_id' => $schema->integer()
                ->description('The occasion being logged, as returned by pending-outcomes. Use this for any scheduled action, including a catch-up from days ago.'),
            'action_id' => $schema->integer()
                ->description('Only for an unscheduled, cue-anchored action. Pass occurred_at with it.'),
            'occurred_at' => $schema->string()
                ->description('ISO-8601 datetime the occasion happened. Required with action_id.'),
            'outcome' => $schema->string()
                ->enum(ActionLog::OUTCOMES)
                ->description('completed, failed, or skipped. skipped means the occasion never happened at all.')
                ->required(),
            'reason' => $schema->string()
                ->description('The user\'s own words, passed through unchanged. Required when the outcome is failed.'),
            'context' => $schema->string()
                ->description('Free text: the mechanics of what happened. The primary record.'),
            'context_fields' => $schema->object()
                ->description('Optional structured context. Only place (string), with_others (boolean) and preceded_by (string) are accepted.'),
        ];
    }
}
