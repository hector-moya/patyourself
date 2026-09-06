<?php

namespace Tests\Unit\Models;

use App\Models\Strategy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_strategy_is_not_concluded(): void
    {
        $strategy = Strategy::factory()->create();

        $this->assertFalse($strategy->isConcluded());
        $this->assertNull($strategy->verdict);
    }

    public function test_a_strategy_with_a_verdict_is_concluded(): void
    {
        $strategy = Strategy::factory()->create([
            'verdict' => Strategy::VERDICT_WORKED,
            'verdict_note' => 'the pause stuck once I put the fork down',
        ]);

        $this->assertTrue($strategy->isConcluded());
    }

    public function test_an_open_ended_experiment_is_never_under_review(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 12:00:00');

        $strategy = Strategy::factory()->create(['review_at' => null]);

        $this->assertFalse($strategy->isUnderReview());
        $this->assertNull($strategy->plannedDays());
    }

    public function test_it_is_under_review_only_after_the_review_date(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 12:00:00');

        $strategy = Strategy::factory()->create(['review_at' => CarbonImmutable::parse('2026-09-10 12:00:00')]);

        $this->assertFalse($strategy->isUnderReview());

        CarbonImmutable::setTestNow('2026-09-11 12:00:00');

        $this->assertTrue($strategy->fresh()->isUnderReview());
    }

    public function test_a_concluded_experiment_is_no_longer_under_review(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 12:00:00');

        $strategy = Strategy::factory()->create([
            'review_at' => CarbonImmutable::parse('2026-09-10 12:00:00'),
            'verdict' => Strategy::VERDICT_FAILED,
        ]);

        $this->assertFalse($strategy->isUnderReview());
    }

    public function test_a_superseded_strategy_with_no_verdict_is_no_longer_under_review(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 12:00:00');

        // The ordinary flow: StartExperiment supersedes the outgoing version
        // without ever writing a verdict or clearing review_at, when the owner
        // starts the next experiment instead of formally concluding this one.
        $strategy = Strategy::factory()->create([
            'status' => Strategy::STATUS_SUPERSEDED,
            'review_at' => CarbonImmutable::parse('2026-09-10 12:00:00'),
            'verdict' => null,
        ]);

        $this->assertFalse($strategy->isUnderReview());
    }

    public function test_it_counts_the_days_of_the_experiment(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $strategy = Strategy::factory()->create([
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
            'review_at' => CarbonImmutable::parse('2026-09-22 12:00:00'),
        ]);

        $this->assertSame(12, $strategy->dayOfExperiment());
        $this->assertSame(21, $strategy->plannedDays());
    }

    /**
     * A version that has been superseded stopped running the moment its
     * successor began. The experiment ladder renders every version, so without
     * a cap a version replaced in July reports a day count that is still
     * climbing today — history that keeps moving, which is the one thing this
     * notebook says it does not do.
     */
    public function test_a_superseded_version_stops_counting_when_its_successor_began(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $superseded = Strategy::factory()->create([
            'status' => Strategy::STATUS_SUPERSEDED,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
        ]);

        Strategy::factory()->create([
            'intention_id' => $superseded->intention_id,
            'version' => $superseded->version + 1,
            'parent_strategy_id' => $superseded->id,
            'created_at' => CarbonImmutable::parse('2026-09-05 12:00:00'),
        ]);

        // Four days, not the twelve it would report counting to now.
        $this->assertSame(4, $superseded->fresh()->dayOfExperiment());
    }

    /**
     * The version still running keeps counting, verdict or not. Concluding does
     * not supersede — a strategy concluded as `worked` stays active and keeps
     * running — so freezing its day count would stop the clock on the
     * experiment the loop screen is currently showing.
     */
    public function test_the_running_version_keeps_counting_even_once_concluded(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $running = Strategy::factory()->create([
            'status' => Strategy::STATUS_ACTIVE,
            'verdict' => Strategy::VERDICT_WORKED,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
        ]);

        $this->assertSame(12, $running->dayOfExperiment());
    }

    /**
     * The cap is the successor's own start, so it does not drift. `updated_at`
     * would have been the cheaper proxy and is the wrong one: any later write
     * to a superseded row would move it, which is the same defect already
     * recorded against dating concluded experiments by `updated_at`.
     */
    public function test_the_cap_does_not_move_when_the_superseded_row_is_written_again(): void
    {
        CarbonImmutable::setTestNow('2026-09-13 12:00:00');

        $superseded = Strategy::factory()->create([
            'status' => Strategy::STATUS_SUPERSEDED,
            'created_at' => CarbonImmutable::parse('2026-09-01 12:00:00'),
        ]);

        Strategy::factory()->create([
            'intention_id' => $superseded->intention_id,
            'version' => $superseded->version + 1,
            'parent_strategy_id' => $superseded->id,
            'created_at' => CarbonImmutable::parse('2026-09-05 12:00:00'),
        ]);

        $superseded->update(['superseded_reason' => 'edited long after the fact']);

        $this->assertSame(4, $superseded->fresh()->dayOfExperiment());
    }
}
