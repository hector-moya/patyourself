<?php

namespace App\Mcp\Tools;

use App\Actions\ConcludeExperiment;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Companion\CompanionAnnouncement;
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
    public function handle(Request $request, ConcludeExperiment $conclude, CompanionAnnouncement $companion): Response
    {
        $validated = $request->validate($this->rules(), [
            'note.required_if' => 'A failed verdict needs a note saying what the evidence showed. A failure is only useful to the next experiment if it carries its reason.',
        ]);

        $note = $validated['note'] ?? null;

        $loop = $request->user()->intentions()->with('activeStrategy')->find($validated['intention_id']);

        if (! $loop instanceof Intention) {
            return Response::error('Not found.');
        }

        $current = $loop->activeStrategy;

        if (! $current instanceof Strategy) {
            return Response::error('That loop has no active strategy version to conclude.');
        }

        // Read before the write, so the comparison afterwards is against where
        // Blob actually stood rather than where it stands now.
        $stageBefore = $companion->stageFor($request->user());

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
            // Present only when concluding moved Blob up a stage, and then the
            // message is relayed exactly as written. Composing praise here would
            // put the app's one warm voice in the model's hands.
            ...$companion->since($request->user(), $stageBefore),
        ]);
    }

    /**
     * The MCP twin of StoreVerdictRequest's rules.
     *
     * Extracted from handle() so ExperimentBoundaryParityTest can read this
     * boundary's rule array directly rather than string-matching the class
     * source. See StartExperimentTool::rules() for why that distinction
     * matters.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'intention_id' => ['required', 'integer'],
            'verdict' => ['required', 'string', Rule::in(Strategy::VERDICTS)],
            // A failure carries its reason. Guarded here rather than in the
            // Action, whose contract other callers already depend on.
            //
            // required_if is an implicit rule, so `nullable` does not exempt it,
            // and validateRequired() trims before testing — so a missing, null
            // or whitespace-only note all fail here. No manual guard is needed;
            // the message is customised in handle() because the caller is a
            // model, and "say what the evidence showed" is more actionable
            // than the default.
            'note' => ['required_if:verdict,'.Strategy::VERDICT_FAILED, 'nullable', 'string', 'max:2000'],
        ];
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
