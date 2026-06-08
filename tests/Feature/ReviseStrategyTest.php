<?php

namespace Tests\Feature;

use App\Actions\ReviseStrategy;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredStrategy;
use App\Services\Coach\Contracts\CoachService;
use App\Services\Coach\FakeCoachService;
use App\Services\Coach\Strategy\StrategyTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviseStrategyTest extends TestCase
{
    use RefreshDatabase;

    private FakeCoachService $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coach = new FakeCoachService;
        $this->app->instance(CoachService::class, $this->coach);
    }

    private function activeStrategy(string $point = Strategy::POINT_RESPONSE): Strategy
    {
        $intention = Intention::factory()->create();

        return Strategy::factory()->initial()->for($intention)->create([
            'intervention_point' => $point,
            'approach' => 'Walk for 15 minutes after coffee.',
        ]);
    }

    /** @return array<string, mixed> */
    private function revision(string $point, string $approach): array
    {
        return [
            'intervention_point' => $point,
            'approach' => $approach,
            'rationale' => 'Because it should help.',
        ];
    }

    public function test_restrategize_on_failure_creates_new_version_and_keeps_history(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);
        $this->coach->pushJson($this->revision(Strategy::POINT_CUE, 'Lay shoes out the night before.'));

        $next = app(ReviseStrategy::class)->restrategizeOnFailure(
            $current,
            'Too tired to walk after work',
        );

        // New active version supersedes the old one.
        $this->assertSame(2, $next->version);
        $this->assertSame(Strategy::STATUS_ACTIVE, $next->status);
        $this->assertSame(Strategy::POINT_CUE, $next->intervention_point);
        $this->assertSame(Strategy::REASON_RESTRATEGIZED_ON_FAILURE, $next->change_reason);
        $this->assertSame($current->id, $next->parent_strategy_id);

        // The intervention point moved earlier up the chain; recorded for the UI.
        $this->assertSame(Strategy::POINT_RESPONSE, $next->metadata['previous_point']);
        $this->assertSame('earlier', $next->metadata['direction']);

        // History is not rewritten in place: the old version is superseded and
        // keeps the user-stated reason, with its original approach intact.
        $current->refresh();
        $this->assertSame(Strategy::STATUS_SUPERSEDED, $current->status);
        $this->assertSame('Too tired to walk after work', $current->superseded_reason);
        $this->assertSame('Walk for 15 minutes after coffee.', $current->approach);

        // Exactly one active version remains for the intention.
        $this->assertSame(2, $current->intention->strategies()->count());
        $this->assertSame(1, $current->intention->strategies()->where('status', Strategy::STATUS_ACTIVE)->count());
    }

    public function test_stack_on_success_creates_harder_next_version(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);
        $this->coach->pushJson($this->revision(Strategy::POINT_RESPONSE, 'Walk for 25 minutes after coffee.'));

        $next = app(ReviseStrategy::class)->stackOnSuccess($current);

        $this->assertSame(2, $next->version);
        $this->assertSame(Strategy::STATUS_ACTIVE, $next->status);
        $this->assertSame(Strategy::REASON_STACKED_ON_SUCCESS, $next->change_reason);
        $this->assertSame($current->id, $next->parent_strategy_id);
        $this->assertSame('same', $next->metadata['direction']);

        $current->refresh();
        $this->assertSame(Strategy::STATUS_SUPERSEDED, $current->status);
        // Success carries no failure reason.
        $this->assertNull($current->superseded_reason);
    }

    public function test_accepts_a_preauthored_strategy_without_calling_the_coach(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);

        $next = app(ReviseStrategy::class)->restrategizeOnFailure(
            $current,
            'reason',
            new AuthoredStrategy(Strategy::POINT_CRAVING, 'Pre-commit with a friend.', 'Accountability.'),
        );

        $this->assertSame(Strategy::POINT_CRAVING, $next->intervention_point);
        $this->assertSame([], $this->coach->requests, 'a pre-authored strategy must not hit the coach');
    }

    public function test_only_an_active_strategy_can_transition(): void
    {
        $current = $this->activeStrategy();
        $current->update(['status' => Strategy::STATUS_SUPERSEDED]);

        $this->expectException(StrategyTransitionException::class);

        try {
            app(ReviseStrategy::class)->stackOnSuccess(
                $current,
                new AuthoredStrategy(Strategy::POINT_CUE, 'x', null),
            );
        } finally {
            // No new version was written.
            $this->assertSame(1, $current->intention->strategies()->count());
        }
    }
}
