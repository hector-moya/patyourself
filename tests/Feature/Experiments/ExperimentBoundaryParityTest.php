<?php

namespace Tests\Feature\Experiments;

use App\Http\Requests\StoreExperimentRequest;
use App\Http\Requests\StoreVerdictRequest;
use App\Mcp\Tools\ConcludeExperimentTool;
use App\Mcp\Tools\StartExperimentTool;
use App\Models\Strategy;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\In;
use Tests\TestCase;

/**
 * The web form and the MCP tool are two doors onto the same writers, and
 * AuthoredStrategy guards nothing itself. If the two boundaries drift, one door
 * starts accepting values the other rejects and the difference is invisible
 * until bad data is already written.
 *
 * The tests below compare the two boundaries' actual rule arrays (extracted via
 * StartExperimentTool::rules() / ConcludeExperimentTool::rules(), the MCP twins
 * of StoreExperimentRequest::rules() / StoreVerdictRequest::rules()) rather than
 * asserting each side was independently built from the model's constants. Two
 * boundaries can each be "built from constants" and still disagree — which is
 * exactly what happened with review_after_days's minimum before this test
 * existed: the web side allowed 0, the MCP side never did, and the three
 * constant-sourcing tests below stayed green throughout.
 */
class ExperimentBoundaryParityTest extends TestCase
{
    public function test_the_verdict_rule_covers_every_verdict_the_model_defines(): void
    {
        $rules = (new StoreVerdictRequest)->rules();

        foreach (Strategy::VERDICTS as $verdict) {
            $this->assertStringContainsString($verdict, (string) $this->inRule($rules['verdict']));
        }
    }

    public function test_the_intervention_rule_covers_every_point_the_model_defines(): void
    {
        $rules = (new StoreExperimentRequest)->rules();

        foreach (Strategy::INTERVENTION_POINTS as $point) {
            $this->assertStringContainsString($point, (string) $this->inRule($rules['intervention_point']));
        }
    }

    public function test_the_change_reason_rule_covers_every_reason_the_model_defines(): void
    {
        $rules = (new StoreExperimentRequest)->rules();

        foreach (Strategy::CHANGE_REASONS as $reason) {
            $this->assertStringContainsString($reason, (string) $this->inRule($rules['change_reason']));
        }
    }

    /**
     * The divergence that actually shipped: the web request allowed
     * review_after_days = 0 (min:0), which sets review_at to now() and makes
     * Strategy::isUnderReview() true the instant the experiment starts.
     * StartExperimentTool's rule never allowed it (min:1). Comparing the two
     * extracted rule arrays directly is the check that would have caught this
     * before it shipped, rather than after.
     */
    public function test_review_after_days_has_the_same_bounds_on_both_boundaries(): void
    {
        $webRules = (new StoreExperimentRequest)->rules();
        $mcpRules = (new StartExperimentTool)->rules();

        $this->assertSame(
            $mcpRules['review_after_days'],
            $webRules['review_after_days'],
            'review_after_days has drifted between the web request and the MCP tool.',
        );
    }

    /**
     * Free-text experiment fields the two boundaries validate with the
     * identical literal rule array — not merely the same semantics.
     */
    public function test_the_shared_free_text_experiment_fields_use_identical_rules(): void
    {
        $webRules = (new StoreExperimentRequest)->rules();
        $mcpRules = (new StartExperimentTool)->rules();

        foreach (['approach', 'rationale', 'supersedes_reason'] as $field) {
            $this->assertSame(
                $mcpRules[$field],
                $webRules[$field],
                "Rules for [{$field}] have drifted between the web request and the MCP tool.",
            );
        }
    }

    /**
     * intervention_point and change_reason are both Rule::in() instances built
     * independently on each side (see test_the_intervention_rule_covers_every_...
     * / test_the_change_reason_rule_covers_every_... above for the "built from
     * the model's constants" half of that guarantee). Two separately-built
     * Rule::in() objects are never the same instance, so this compares them by
     * their rendered validation string instead.
     */
    public function test_the_intervention_point_and_change_reason_enums_agree_between_boundaries(): void
    {
        $webRules = (new StoreExperimentRequest)->rules();
        $mcpRules = (new StartExperimentTool)->rules();

        $this->assertSame(
            (string) $this->inRule($mcpRules['intervention_point']),
            (string) $this->inRule($webRules['intervention_point']),
        );

        $this->assertSame(
            (string) $this->inRule($mcpRules['change_reason']),
            (string) $this->inRule($webRules['change_reason']),
        );
    }

    public function test_the_verdict_enum_agrees_between_boundaries(): void
    {
        $webRules = (new StoreVerdictRequest)->rules();
        $mcpRules = (new ConcludeExperimentTool)->rules();

        $this->assertSame(
            (string) $this->inRule($mcpRules['verdict']),
            (string) $this->inRule($webRules['verdict']),
        );
    }

    /**
     * `note`'s requirement cannot be compared by array equality: StoreVerdictRequest
     * expresses "required when verdict is failed" as a Rule::requiredIf() closure
     * bound to the request instance's own input, while ConcludeExperimentTool
     * expresses the identical constraint as the string rule
     * required_if:verdict,failed. A bound closure has no structural form to diff
     * against a rule string, so array-equality would either be false for reasons
     * that don't matter or require unwrapping the closure to inspect it — at
     * which point the test is reimplementing the two rule engines instead of
     * comparing them.
     *
     * What is genuinely comparable is behaviour: run the same inputs through
     * Laravel's own Validator against both rule sets and assert they reach the
     * same pass/fail verdict. This is the actual contract users of either
     * boundary depend on.
     */
    public function test_the_note_requirement_agrees_between_boundaries_for_representative_inputs(): void
    {
        // Scoped to verdict/note only: ConcludeExperimentTool also requires
        // intention_id (MCP has no route-bound model to take it from), which
        // StoreVerdictRequest has no equivalent for at all — comparing the
        // full rule sets against data that carries neither would fail both
        // for a reason unrelated to what this test checks.
        $mcpRules = array_intersect_key((new ConcludeExperimentTool)->rules(), ['verdict' => true, 'note' => true]);

        $cases = [
            'failed, no note' => ['verdict' => Strategy::VERDICT_FAILED, 'note' => null],
            'failed, whitespace-only note' => ['verdict' => Strategy::VERDICT_FAILED, 'note' => '   '],
            'failed, a real note' => ['verdict' => Strategy::VERDICT_FAILED, 'note' => 'the cue never fired'],
            'worked, no note' => ['verdict' => Strategy::VERDICT_WORKED, 'note' => null],
            'inconclusive, no note' => ['verdict' => Strategy::VERDICT_INCONCLUSIVE, 'note' => null],
        ];

        foreach ($cases as $label => $data) {
            // Built with the data bound as request input, exactly as it would
            // be when Laravel resolves the FormRequest from a real submission —
            // the requiredIf closure below reads $this->input('verdict').
            $webRequest = StoreVerdictRequest::create('/', 'POST', $data);

            $webFails = Validator::make($data, $webRequest->rules())->fails();
            $mcpFails = Validator::make($data, $mcpRules)->fails();

            $this->assertSame(
                $webFails,
                $mcpFails,
                "The web request and the MCP tool disagree on validity for case [{$label}].",
            );
        }
    }

    /**
     * @param  array<int, mixed>  $rule
     */
    private function inRule(array $rule): In
    {
        foreach ($rule as $part) {
            if ($part instanceof In) {
                return $part;
            }
        }

        $this->fail('Expected an Rule::in() constraint built from the model constants, found none. '
            .'A literal list here is the drift this test exists to catch.');
    }
}
