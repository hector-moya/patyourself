<?php

namespace Tests\Feature\Experiments;

use App\Http\Requests\StoreExperimentRequest;
use App\Http\Requests\StoreVerdictRequest;
use App\Models\Strategy;
use Illuminate\Validation\Rules\In;
use Tests\TestCase;

/**
 * The web form and the MCP tool are two doors onto the same writers, and
 * AuthoredStrategy guards nothing itself. If the two boundaries drift, one door
 * starts accepting values the other rejects and the difference is invisible
 * until bad data is already written.
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
