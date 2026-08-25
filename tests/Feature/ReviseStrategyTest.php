<?php

namespace Tests\Feature;

use App\Actions\ReviseStrategy;
use App\Models\Action;
use App\Models\Intention;
use App\Models\Strategy;
use App\Services\Coach\Authoring\AuthoredAction;
use App\Services\Coach\Authoring\AuthoredStrategy;
use App\Services\Coach\Strategy\StrategyTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviseStrategyTest extends TestCase
{
    use RefreshDatabase;

    private function activeStrategy(string $point = Strategy::POINT_RESPONSE): Strategy
    {
        $intention = Intention::factory()->create();

        return Strategy::factory()->initial()->for($intention)->create([
            'intervention_point' => $point,
            'approach' => 'Walk for 15 minutes after coffee.',
        ]);
    }

    public function test_restrategize_on_failure_creates_new_version_and_keeps_history(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);

        $next = app(ReviseStrategy::class)->restrategizeOnFailure(
            $current,
            'Too tired to walk after work',
            new AuthoredStrategy(Strategy::POINT_CUE, 'Lay shoes out the night before.', 'Because it should help.'),
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

        $next = app(ReviseStrategy::class)->stackOnSuccess(
            $current,
            new AuthoredStrategy(Strategy::POINT_RESPONSE, 'Walk for 25 minutes after coffee.', 'Because it should help.'),
        );

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

    public function test_accepts_a_preauthored_strategy(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);

        $next = app(ReviseStrategy::class)->restrategizeOnFailure(
            $current,
            'reason',
            new AuthoredStrategy(Strategy::POINT_CRAVING, 'Pre-commit with a friend.', 'Accountability.'),
        );

        $this->assertSame(Strategy::POINT_CRAVING, $next->intervention_point);
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

    public function test_revision_archives_the_old_action_and_inherits_the_cadence(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);
        $oldAction = Action::factory()->for($current->intention)->create([
            'strategy_id' => $current->id,
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
            'scheduled_for' => now()->addDay(),
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $next = app(ReviseStrategy::class)->restrategizeOnFailure(
            $current,
            'Too tired after work',
            new AuthoredStrategy(Strategy::POINT_CUE, 'Lay shoes out the night before.', 'Because it should help.'),
        );

        $oldAction->refresh();
        $this->assertSame(Action::STATUS_ARCHIVED, $oldAction->status);

        $newAction = $current->intention->actions()
            ->where('status', Action::STATUS_PENDING)->first();
        $this->assertNotNull($newAction);
        $this->assertSame($next->id, $newAction->strategy_id);
        $this->assertSame('daily', $newAction->recurrence); // inherited
        $this->assertStringContainsString('Lay shoes', $newAction->title); // from the new approach

        // One active Action per active Strategy.
        $this->assertSame(1, $current->intention->actions()
            ->whereIn('status', [Action::STATUS_PENDING, Action::STATUS_ACTIVE])->count());
    }

    public function test_revision_uses_a_reproposed_schedule_when_given(): void
    {
        $current = $this->activeStrategy(Strategy::POINT_RESPONSE);
        Action::factory()->for($current->intention)->create([
            'strategy_id' => $current->id,
            'status' => Action::STATUS_PENDING,
            'recurrence' => 'daily',
        ]);

        $next = new AuthoredStrategy(Strategy::POINT_CUE, 'Walk in the morning instead.', 'Mornings have more energy.');
        $revisedAction = new AuthoredAction(
            title: 'Morning walk',
            description: null,
            kind: 'clock',
            time: '06:30',
            recurrence: 'weekdays',
            anchor: null,
        );

        app(ReviseStrategy::class)->restrategizeOnFailure($current, 'No energy in the evening', $next, $revisedAction);

        $newAction = $current->intention->actions()
            ->where('status', Action::STATUS_PENDING)->first();
        $this->assertSame('weekdays', $newAction->recurrence); // re-proposed, not inherited
        $this->assertSame('Morning walk', $newAction->title);
    }

    public function test_it_requires_a_pre_authored_revision(): void
    {
        $intention = Intention::factory()->create();
        $current = Strategy::factory()->for($intention)->create([
            'status' => Strategy::STATUS_ACTIVE,
            'intervention_point' => Strategy::POINT_RESPONSE,
        ]);

        $next = new AuthoredStrategy(
            interventionPoint: Strategy::POINT_CUE,
            approach: 'put the fork down between bites',
            rationale: 'the gap has to exist before the fullness signal lands',
            promptVersion: 'test@1',
        );

        $revised = app(ReviseStrategy::class)->restrategizeOnFailure($current, 'ate on autopilot', $next);

        $this->assertSame(2, $revised->version);
        $this->assertSame(Strategy::POINT_CUE, $revised->intervention_point);
        $this->assertSame('earlier', $revised->metadata['direction']);
        $this->assertSame(Strategy::STATUS_SUPERSEDED, $current->fresh()->status);
        $this->assertSame('ate on autopilot', $current->fresh()->superseded_reason);
    }
}
