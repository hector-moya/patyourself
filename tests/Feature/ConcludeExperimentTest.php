<?php

namespace Tests\Feature;

use App\Actions\ConcludeExperiment;
use App\Models\Strategy;
use App\Services\Strategy\StrategyTransitionException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ConcludeExperimentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Concluding records the verdict and leaves `review_at` alone.
     *
     * It used to null it. That was both redundant and destructive: redundant
     * because isUnderReview() already short-circuits on `! isConcluded()`, and
     * destructive because plannedDays() derives entirely from `review_at` and
     * there is no `concluded_at` to fall back on — so clearing it erased the
     * only record of how long the experiment was planned to run.
     */
    public function test_concluding_records_the_verdict_and_keeps_the_planned_length(): void
    {
        $reviewAt = CarbonImmutable::parse('2026-09-22 12:00:00');

        $strategy = Strategy::factory()->create([
            'status' => Strategy::STATUS_ACTIVE,
            'created_at' => CarbonImmutable::parse('2026-09-08 12:00:00'),
            'review_at' => $reviewAt,
        ]);

        $concluded = app(ConcludeExperiment::class)->handle(
            $strategy,
            Strategy::VERDICT_WORKED,
            'the pause stuck once I put the fork down',
        );

        $this->assertSame(Strategy::VERDICT_WORKED, $concluded->verdict);
        $this->assertSame('the pause stuck once I put the fork down', $concluded->verdict_note);
        $this->assertTrue($concluded->review_at->equalTo($reviewAt));
        $this->assertSame(14, $concluded->plannedDays());
        $this->assertTrue($concluded->isConcluded());
        $this->assertFalse($concluded->isUnderReview());
    }

    /**
     * The reason the clearing was redundant, pinned directly: a concluded
     * version whose `review_at` is in the past still reports not-under-review,
     * because the verdict is what ends the review, not the absence of a date.
     */
    public function test_a_concluded_version_with_a_past_review_date_is_not_under_review(): void
    {
        $strategy = Strategy::factory()->create([
            'status' => Strategy::STATUS_ACTIVE,
            'review_at' => CarbonImmutable::parse('2026-08-01 12:00:00'),
        ]);

        $this->assertTrue($strategy->isUnderReview());

        $concluded = app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_INCONCLUSIVE);

        $this->assertNotNull($concluded->review_at);
        $this->assertFalse($concluded->isUnderReview());
    }

    public function test_a_strategy_that_worked_keeps_running(): void
    {
        $strategy = Strategy::factory()->create(['status' => Strategy::STATUS_ACTIVE]);

        $concluded = app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_WORKED);

        $this->assertSame(Strategy::STATUS_ACTIVE, $concluded->status);
        $this->assertTrue($concluded->isActive());
    }

    /**
     * Concluding is not superseding, even for the verdict most likely to tempt
     * a future implementer into superseding on the way out: a `failed`
     * conclusion must not change status or spawn a new version. That is
     * StartExperiment's job, triggered separately when the *next* experiment
     * begins.
     */
    public function test_concluding_as_failed_does_not_supersede_or_create_a_new_version(): void
    {
        $strategy = Strategy::factory()->create(['status' => Strategy::STATUS_SUPERSEDED]);

        $concluded = app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_FAILED, 'kept skipping the walk');

        $this->assertSame(Strategy::STATUS_SUPERSEDED, $concluded->status);
        $this->assertSame(Strategy::VERDICT_FAILED, $concluded->verdict);
        $this->assertSame(1, Strategy::query()->count());
    }

    public function test_it_rejects_an_unknown_verdict(): void
    {
        $strategy = Strategy::factory()->create(['status' => Strategy::STATUS_ACTIVE]);

        $this->expectException(InvalidArgumentException::class);

        app(ConcludeExperiment::class)->handle($strategy, 'sort-of-worked');
    }

    public function test_it_refuses_to_conclude_twice(): void
    {
        $strategy = Strategy::factory()->create([
            'status' => Strategy::STATUS_ACTIVE,
            'verdict' => Strategy::VERDICT_FAILED,
        ]);

        $this->expectException(StrategyTransitionException::class);

        app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_WORKED);
    }
}
