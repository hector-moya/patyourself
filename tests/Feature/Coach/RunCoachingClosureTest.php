<?php

namespace Tests\Feature\Coach;

use App\Ai\Agents\Strategist;
use App\Ai\Agents\Summarizer;
use App\Events\ActionLogged;
use App\Listeners\RunCoachingClosure;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Notifications\StrategyRevisedNotification;
use App\Services\Coach\Exceptions\CoachQuotaException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RunCoachingClosureTest extends TestCase
{
    use RefreshDatabase;

    private Intention $intention;

    private Strategy $strategy;

    private Action $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intention = Intention::factory()->create();
        $this->strategy = Strategy::factory()->initial()->for($this->intention)->create([
            'intervention_point' => Strategy::POINT_RESPONSE,
            'approach' => 'Walk 15 minutes after coffee.',
        ]);
        $this->action = Action::factory()
            ->for($this->intention)
            ->for($this->strategy)
            ->create(['status' => Action::STATUS_ACTIVE]);
    }

    /** @param array<int, array{0:string, 1?:?string}> $outcomes */
    private function logs(array $outcomes): ActionLog
    {
        $last = null;
        foreach ($outcomes as $i => [$outcome, $reason]) {
            $last = ActionLog::factory()->for($this->action)->for($this->intention->user)->create([
                'outcome' => $outcome,
                'reason' => $reason ?? null,
                'logged_at' => now()->addMinutes($i),
            ]);
        }

        return $last;
    }

    private function fire(ActionLog $log): void
    {
        app(RunCoachingClosure::class)->handle(
            new ActionLogged($this->intention->user, $this->action->fresh(), $log),
        );
    }

    private function strategyRevision(string $point, string $approach): array
    {
        return ['intervention_point' => $point, 'approach' => $approach, 'rationale' => 'Because.'];
    }

    public function test_two_failures_restrategize_the_active_strategy(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'Two misses.', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_CUE, 'Lay shoes out the night before.')]);

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'Too tired'],
            [ActionLog::OUTCOME_FAILED, 'Got home late'],
        ]);

        $this->fire($log);

        $this->assertSame(2, $this->intention->strategies()->max('version'));
        $new = $this->intention->activeStrategy()->first();
        $this->assertSame(Strategy::REASON_RESTRATEGIZED_ON_FAILURE, $new->change_reason);
        $this->assertSame('Got home late', $this->strategy->fresh()->superseded_reason);
        Notification::assertSentTo($this->intention->user, StrategyRevisedNotification::class);
    }

    public function test_a_skip_between_failures_still_triggers_restrategize(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'Skip then miss.', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_CUE, 'Try a smaller step.')]);

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'First miss'],
            [ActionLog::OUTCOME_SKIPPED, null],
            [ActionLog::OUTCOME_FAILED, 'Latest miss'],
        ]);

        $this->fire($log);

        $this->assertSame(2, $this->intention->strategies()->max('version'));
        $new = $this->intention->activeStrategy()->first();
        $this->assertSame(Strategy::REASON_RESTRATEGIZED_ON_FAILURE, $new->change_reason);
        $this->assertSame('Latest miss', $this->strategy->fresh()->superseded_reason);
        Notification::assertSentTo($this->intention->user, StrategyRevisedNotification::class);
    }

    public function test_five_completions_stack_the_active_strategy(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'Five wins.', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_RESPONSE, 'Walk 25 minutes after coffee.')]);

        $log = $this->logs(array_fill(0, 5, [ActionLog::OUTCOME_COMPLETED, null]));

        $this->fire($log);

        $new = $this->intention->activeStrategy()->first();
        $this->assertSame(2, $new->version);
        $this->assertSame(Strategy::REASON_STACKED_ON_SUCCESS, $new->change_reason);
        Notification::assertSentTo($this->intention->user, StrategyRevisedNotification::class);
    }

    public function test_below_threshold_updates_summary_but_does_not_revise(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'One miss so far.', 'patterns' => []]]);
        Strategist::fake([]);

        $log = $this->logs([[ActionLog::OUTCOME_FAILED, 'Just once']]);

        $this->fire($log);

        Strategist::assertNeverPrompted();
        $this->assertSame(1, $this->intention->strategies()->count());
        $this->assertSame(1, $this->intention->summaries()->count());
        Notification::assertNothingSent();
    }

    public function test_skipped_alone_never_revises(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'A skip.', 'patterns' => []]]);
        Strategist::fake([]);

        $log = $this->logs([[ActionLog::OUTCOME_SKIPPED, null]]);

        $this->fire($log);

        Strategist::assertNeverPrompted();
        Notification::assertNothingSent();
    }

    public function test_revision_is_idempotent_on_a_second_run(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'a', 'patterns' => []], ['content' => 'b', 'patterns' => []]]);
        Strategist::fake([$this->strategyRevision(Strategy::POINT_CUE, 'Lay shoes out.')]);

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'one'],
            [ActionLog::OUTCOME_FAILED, 'two'],
        ]);

        $this->fire($log);
        $this->fire($log); // second delivery

        // Exactly one new version; the new active strategy has no logs so it does not re-revise.
        $this->assertSame(2, $this->intention->strategies()->max('version'));
    }

    public function test_quota_exhaustion_is_swallowed_and_skips_revision(): void
    {
        Notification::fake();
        Summarizer::fake([['content' => 'x', 'patterns' => []]]);

        // The revision's Strategist call trips the budget guard. The listener must
        // swallow CoachQuotaException, leaving the active strategy unrevised.
        Strategist::fake(function (): never {
            throw CoachQuotaException::dailyTokenBudget($this->intention->user, 200000, 200001);
        });

        $log = $this->logs([
            [ActionLog::OUTCOME_FAILED, 'one'],
            [ActionLog::OUTCOME_FAILED, 'two'],
        ]);

        $this->fire($log); // must not throw

        $this->assertSame(1, $this->intention->strategies()->count());
        Notification::assertNothingSent();
    }

    public function test_listener_is_registered_for_the_event(): void
    {
        Event::fake();

        Event::assertListening(ActionLogged::class, RunCoachingClosure::class);
    }
}
