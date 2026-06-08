<?php

namespace Tests\Unit\Coach;

use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\FakeCoachService;
use App\Services\Coach\Strategy\StrategyReviser;
use App\Services\Coach\Strategy\StrategyTransitionException;
use Tests\TestCase;

class StrategyReviserTest extends TestCase
{
    /** A current, active strategy with its intention loaded — no DB needed. */
    private function currentStrategy(): Strategy
    {
        $intention = new Intention([
            'title' => 'Morning walk',
            'type' => Intention::TYPE_BUILD,
            'cue' => 'Coffee finishes brewing',
            'craving' => 'Feel awake',
            'response' => 'Walk 15 minutes',
            'reward' => 'Energy',
        ]);

        $strategy = new Strategy([
            'version' => 1,
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Walk for 15 minutes after coffee.',
        ]);
        $strategy->setRelation('intention', $intention);

        return $strategy;
    }

    /** @return array<string, mixed> */
    private function revision(array $overrides = []): array
    {
        return array_replace([
            'intervention_point' => 'cue',
            'approach' => 'Lay walking shoes by the coffee machine the night before.',
            'rationale' => 'Make the cue impossible to miss.',
        ], $overrides);
    }

    public function test_restrategize_authors_a_new_strategy_from_the_failure_reason(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->revision());

        $next = (new StrategyReviser($coach))->restrategize(
            $this->currentStrategy(),
            'I was too tired to walk after work',
        );

        $this->assertSame('cue', $next->interventionPoint);
        $this->assertNotSame('', $next->approach);

        $request = $coach->requests[0];
        $this->assertTrue($request->json);

        $system = (string) $request->resolveSystem();
        $this->assertStringContainsString('cue', $system);
        $this->assertStringContainsString('reward', $system);

        // The user-stated failure reason must reach the model.
        $userTurn = $request->messagePayload()[0]['content'];
        $this->assertStringContainsString('too tired', $userTurn);
    }

    public function test_stack_authors_a_harder_next_version(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->revision([
            'intervention_point' => 'response',
            'approach' => 'Walk for 25 minutes after coffee.',
        ]));

        $next = (new StrategyReviser($coach))->stack($this->currentStrategy());

        $this->assertSame('response', $next->interventionPoint);
        $this->assertStringContainsString('25 minutes', $next->approach);

        $userTurn = $coach->requests[0]->messagePayload()[0]['content'];
        // Stacking is triggered by success — the prompt must convey that.
        $this->assertMatchesRegularExpression('/succe|worked|stack|harder/i', $userTurn);
    }

    public function test_rejects_invalid_intervention_point(): void
    {
        $coach = (new FakeCoachService)->pushJson($this->revision(['intervention_point' => 'nowhere']));

        $this->expectException(StrategyTransitionException::class);
        (new StrategyReviser($coach))->restrategize($this->currentStrategy(), 'reason');
    }

    public function test_rejects_missing_approach(): void
    {
        $payload = $this->revision();
        unset($payload['approach']);
        $coach = (new FakeCoachService)->pushJson($payload);

        $this->expectException(StrategyTransitionException::class);
        (new StrategyReviser($coach))->stack($this->currentStrategy());
    }
}
