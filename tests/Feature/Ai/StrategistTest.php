<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\Strategist;
use Tests\TestCase;

class StrategistTest extends TestCase
{
    public function test_returns_a_structured_strategy_revision(): void
    {
        Strategist::fake([
            [
                'intervention_point' => 'response',
                'approach' => 'Read a single page, no more',
                'rationale' => 'Shrink the response',
            ],
        ]);

        $response = (new Strategist)->prompt('The user failed because: too tired.');

        $this->assertSame('response', $response->structured['intervention_point'] ?? null);
        $this->assertSame('Read a single page, no more', $response->structured['approach'] ?? null);
    }

    public function test_carries_a_prompt_version(): void
    {
        $this->assertNotSame('', Strategist::PROMPT_VERSION);
    }
}
