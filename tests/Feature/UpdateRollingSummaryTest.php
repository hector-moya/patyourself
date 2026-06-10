<?php

namespace Tests\Feature;

use App\Actions\UpdateRollingSummary;
use App\Ai\Agents\Summarizer;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateRollingSummaryTest extends TestCase
{
    use RefreshDatabase;

    private Intention $intention;

    private Action $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->intention = Intention::factory()->create();
        $strategy = Strategy::factory()->initial()->for($this->intention)->create();
        $this->action = Action::factory()->for($this->intention)->for($strategy)->create();
    }

    private function log(string $outcome, ?string $reason, string $loggedAt): ActionLog
    {
        return ActionLog::factory()->for($this->action)->create([
            'user_id' => $this->intention->user_id,
            'outcome' => $outcome,
            'reason' => $reason,
            'logged_at' => $loggedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(string $content = 'Rolling summary text.'): array
    {
        return ['content' => $content, 'patterns' => ['Fails on late workdays']];
    }

    public function test_creates_a_summary_snapshot_from_new_events(): void
    {
        $this->log(ActionLog::OUTCOME_FAILED, 'Got home too late', '2026-06-01 22:00:00');
        $this->log(ActionLog::OUTCOME_COMPLETED, null, '2026-06-02 22:00:00');
        Summarizer::fake([$this->payload('Misses on late workdays.')]);

        $summary = app(UpdateRollingSummary::class)->handle($this->intention);

        $this->assertNotNull($summary);
        $this->assertSame(Summary::SCOPE_INTENTION, $summary->scope);
        $this->assertSame($this->intention->id, $summary->intention_id);
        $this->assertSame($this->intention->user_id, $summary->user_id);
        $this->assertSame('Misses on late workdays.', $summary->content);
        $this->assertSame(2, $summary->events_count);
        $this->assertSame(['Fails on late workdays'], $summary->metadata['patterns']);
        $this->assertSame('claude-sonnet-4-6', $summary->metadata['model']);

        // It becomes the intention's current rolling summary.
        $this->assertSame($summary->id, $this->intention->fresh()->latestSummary->id);

        // The failure reason reached the Summarizer agent.
        Summarizer::assertPrompted(fn ($p) => str_contains($p->prompt, 'Got home too late'));
    }

    public function test_rolling_update_folds_prior_summary_and_counts_only_new_events(): void
    {
        $this->log(ActionLog::OUTCOME_COMPLETED, null, '2026-06-01 22:00:00');
        $this->log(ActionLog::OUTCOME_COMPLETED, null, '2026-06-02 22:00:00');
        Summarizer::fake([$this->payload('FIRST_SUMMARY')]);
        $first = app(UpdateRollingSummary::class)->handle($this->intention);

        // A new event after the first window.
        $this->log(ActionLog::OUTCOME_FAILED, 'Travelling', '2026-06-05 22:00:00');
        Summarizer::fake([$this->payload('SECOND_SUMMARY')]);
        $second = app(UpdateRollingSummary::class)->handle($this->intention);

        $this->assertNotNull($second);
        $this->assertSame('SECOND_SUMMARY', $second->content);
        $this->assertSame(1, $second->events_count, 'only events after the prior window are folded');
        $this->assertEquals(
            $first->window_end->toDateTimeString(),
            $second->window_start->toDateTimeString(),
            'windows are contiguous',
        );

        // The prior rolling summary was fed back to the Summarizer agent.
        Summarizer::assertPrompted(fn ($p) => str_contains($p->prompt, 'FIRST_SUMMARY'));

        $this->assertSame(2, Summary::count());
    }

    public function test_returns_null_when_there_are_no_new_events(): void
    {
        $this->log(ActionLog::OUTCOME_COMPLETED, null, '2026-06-01 22:00:00');
        Summarizer::fake([$this->payload()]);
        app(UpdateRollingSummary::class)->handle($this->intention);

        // No new events logged since the first summary.
        $result = app(UpdateRollingSummary::class)->handle($this->intention);

        $this->assertNull($result);
        $this->assertSame(1, Summary::count());
    }

    public function test_returns_null_when_the_loop_has_no_events_at_all(): void
    {
        $result = app(UpdateRollingSummary::class)->handle($this->intention);

        $this->assertNull($result);
        $this->assertSame(0, Summary::count());
    }
}
