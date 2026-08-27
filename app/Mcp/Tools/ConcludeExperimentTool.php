<?php

namespace App\Mcp\Tools;

use App\Actions\ConcludeExperiment;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('conclude-experiment')]
#[Description(<<<'TEXT'
End the loop's current experiment with a verdict: worked, failed, or
inconclusive. Read loop-progress first and judge the version on its own
evidence, not the loop's lifetime record.

Concluding is not superseding. A version concluded as `worked` stays active and
keeps running — use start-experiment when the next experiment begins, which is a
separate decision the owner takes.

The note is about the strategy, never about the user: say what the evidence
showed. It is required for a `failed` verdict, because a failure is only useful
to the next experiment if it carries its reason, and it is recorded verbatim.

`inconclusive` is a real answer. Use it when the experiment did not run long
enough or cleanly enough to judge, rather than forcing a verdict the evidence
does not support.
TEXT)]
class ConcludeExperimentTool extends Tool
{
    public function handle(Request $request, ConcludeExperiment $conclude): Response
    {
        $validated = $request->validate([
            'intention_id' => ['required', 'integer'],
            'verdict' => ['required', 'string', Rule::in(Strategy::VERDICTS)],
            // A failure carries its reason. Guarded here rather than in the
            // Action, whose contract other callers already depend on — the same
            // boundary where start-experiment guards the intervention point.
            'note' => ['required_if:verdict,'.Strategy::VERDICT_FAILED, 'nullable', 'string', 'max:2000'],
        ]);

        $note = $validated['note'] ?? null;

        if ($validated['verdict'] === Strategy::VERDICT_FAILED && trim((string) $note) === '') {
            return Response::error('A failed verdict needs a note saying what the evidence showed.');
        }

        $loop = $request->user()->intentions()->with('activeStrategy')->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $current = $loop->activeStrategy;

        if (! $current instanceof Strategy) {
            return Response::error('That loop has no active strategy version to conclude.');
        }

        try {
            $concluded = $conclude->handle($current, $validated['verdict'], $note);
        } catch (StrategyTransitionException $e) {
            return Response::error($e->getMessage());
        }

        return Response::json([
            'loop_id' => $loop->id,
            'version' => $concluded->version,
            'status' => $concluded->status,
            'intervention_point' => $concluded->intervention_point,
            'verdict' => $concluded->verdict,
            // Verbatim, as given.
            'verdict_note' => $concluded->verdict_note,
            // Kept, not cleared: plannedDays() derives from review_at and there
            // is no concluded_at, so nulling it would erase how long this
            // experiment was planned to run.
            'review_at' => $concluded->review_at?->toIso8601String(),
            'planned_days' => $concluded->plannedDays(),
            'day_of_experiment' => $concluded->dayOfExperiment(),
            'is_under_review' => $concluded->isUnderReview(),
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
            'verdict' => $schema->string()
                ->enum(Strategy::VERDICTS)
                ->description('How the experiment turned out. inconclusive is a real answer when the evidence is too thin to judge.')
                ->required(),
            'note' => $schema->string()
                ->description('What the evidence showed, about the strategy rather than the user. Required for a failed verdict; recorded verbatim.'),
        ];
    }
}
