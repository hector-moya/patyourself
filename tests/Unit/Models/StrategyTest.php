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
}
