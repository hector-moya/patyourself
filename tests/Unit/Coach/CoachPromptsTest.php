<?php

namespace Tests\Unit\Coach;

use App\Services\Coach\Prompts\CoachPrompts;
use App\Services\Coach\Strategy\StrategyRevisionSchema;
use PHPUnit\Framework\TestCase;

class CoachPromptsTest extends TestCase
{
    /** @return list<string> */
    private function allSystems(): array
    {
        return [
            CoachPrompts::intentionAuthoring()->system,
            CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_STACK)->system,
            CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_RESTRATEGIZE)->system,
            CoachPrompts::rollingSummary()->system,
        ];
    }

    public function test_every_prompt_shares_the_cbt_and_atomic_habits_charter(): void
    {
        foreach ($this->allSystems() as $system) {
            $lower = mb_strtolower($system);
            // Atomic Habits chain.
            foreach (['cue', 'craving', 'response', 'reward'] as $point) {
                $this->assertStringContainsString($point, $lower);
            }
            // CBT framing.
            $this->assertStringContainsString('cbt', $lower);
            $this->assertStringContainsString('experiment', $lower);
        }
    }

    public function test_every_prompt_is_versioned(): void
    {
        $prompts = [
            CoachPrompts::intentionAuthoring(),
            CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_STACK),
            CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_RESTRATEGIZE),
            CoachPrompts::rollingSummary(),
        ];

        $versions = [];
        foreach ($prompts as $prompt) {
            $this->assertNotSame('', $prompt->version);
            $this->assertNotSame('', $prompt->name);
            $versions[] = $prompt->version;
        }

        // Distinct purposes carry distinct versions.
        $this->assertSame($versions, array_values(array_unique($versions)));
    }

    public function test_authoring_prompt_carries_the_intention_contract(): void
    {
        $system = CoachPrompts::intentionAuthoring()->system;

        foreach (['title', 'type', 'cue', 'craving', 'response', 'reward', 'intervention_point'] as $field) {
            $this->assertStringContainsString($field, $system);
        }
    }

    public function test_stack_and_restrategize_framings_differ(): void
    {
        $stack = CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_STACK)->system;
        $restrategize = CoachPrompts::strategyRevision(StrategyRevisionSchema::MODE_RESTRATEGIZE)->system;

        $this->assertNotSame($stack, $restrategize);
        $this->assertMatchesRegularExpression('/harder|stack|succeeded/i', $stack);
        $this->assertMatchesRegularExpression('/reason|failed|move/i', $restrategize);
    }

    public function test_rolling_summary_prompt_describes_pattern_detection(): void
    {
        $lower = mb_strtolower(CoachPrompts::rollingSummary()->system);

        $this->assertStringContainsString('pattern', $lower);
        $this->assertStringContainsString('summary', $lower);
        $this->assertStringContainsString('content', $lower);
    }
}
