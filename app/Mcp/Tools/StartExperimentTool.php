<?php

namespace App\Mcp\Tools;

use App\Actions\StartExperiment;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Authoring\AuthoredStrategy;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('start-experiment')]
#[Description(<<<'TEXT'
Start the next experiment on a loop: supersede the active strategy version and
activate a new one. Versions are append-only — nothing is ever edited, so a
version you get wrong is fixed by writing the next one, not by correcting it.

Read loop-outcomes first and move the intervention to where the chain is
actually breaking. supersedes_reason is about the strategy, not the user: say
why this intervention point stopped being the right one.

Pass review_after_days only when a planned length is genuinely useful. Leaving
it out makes the experiment open-ended, which is a perfectly good state.
TEXT)]
class StartExperimentTool extends Tool
{
    public function handle(Request $request, StartExperiment $start): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            // The guard that lost its home when ReviseStrategy was deleted:
            // AuthoredStrategy has none of its own, so this boundary is the only
            // thing standing between a malformed version and the database.
            'intervention_point' => ['required', 'string', Rule::in(Strategy::INTERVENTION_POINTS)],
            'approach' => ['required', 'string', 'min:1', 'max:2000'],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'supersedes_reason' => ['nullable', 'string', 'max:2000'],
            'review_after_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'change_reason' => ['nullable', 'string', Rule::in(Strategy::CHANGE_REASONS)],
        ]);

        if (trim($validated['approach']) === '') {
            return Response::error('approach cannot be blank.');
        }

        $loop = $request->user()->intentions()->with('activeStrategy')->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $current = $loop->activeStrategy;

        if (! $current instanceof Strategy) {
            return Response::error('That loop has no active strategy version to supersede.');
        }

        $next = $start->handle(
            $current,
            new AuthoredStrategy(
                interventionPoint: $validated['intervention_point'],
                approach: $validated['approach'],
                rationale: $validated['rationale'] ?? null,
            ),
            $validated['change_reason'] ?? Strategy::REASON_RESTRATEGIZED_ON_FAILURE,
            $validated['supersedes_reason'] ?? null,
            $validated['review_after_days'] ?? null,
        );

        return Response::json([
            'loop_id' => $loop->id,
            'version' => $next->version,
            'status' => $next->status,
            'intervention_point' => $next->intervention_point,
            'approach' => $next->approach,
            'rationale' => $next->rationale,
            'review_at' => $next->review_at?->toIso8601String(),
            'planned_days' => $next->plannedDays(),
            'superseded' => [
                'version' => $current->version,
                'superseded_reason' => $current->fresh()?->superseded_reason,
            ],
        ]);
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
            'intervention_point' => $schema->string()
                ->enum(Strategy::INTERVENTION_POINTS)
                ->description('Which point of the cue -> craving -> response -> reward chain this experiment intervenes on.')
                ->required(),
            'approach' => $schema->string()
                ->description('What the user will actually do differently. One concrete change, in their language.')
                ->required(),
            'rationale' => $schema->string()
                ->description('The hypothesis: why this intervention point should hold where the last one did not.'),
            'supersedes_reason' => $schema->string()
                ->description('Why the outgoing version stopped being the right one. About the strategy, never about the user.'),
            'review_after_days' => $schema->integer()
                ->description('Planned run length in days. Omit for an open-ended experiment.'),
            'change_reason' => $schema->string()
                ->enum(Strategy::CHANGE_REASONS)
                ->description('Defaults to restrategized_on_failure. Use stacked_on_success when the previous version worked and this one builds on it.'),
        ];
    }
}
