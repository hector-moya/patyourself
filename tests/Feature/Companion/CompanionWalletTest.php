<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\Summary;
use App\Models\User;
use App\Services\Companion\CompanionWallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the record pays, and what spending does to it.
 *
 * Earned is derived on every read, exactly as the ladder's counts are; only the
 * spending is stored. So there is no number here that can silently drift — the
 * worst case is a balance that falls, which takes nothing away.
 */
class CompanionWalletTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        $this->base = CarbonImmutable::parse('2026-03-02T09:00:00+00:00');
    }

    private function wallet(): CompanionWallet
    {
        return app(CompanionWallet::class);
    }

    private function log(User $user, CarbonImmutable $at, string $outcome = ActionLog::OUTCOME_COMPLETED): void
    {
        ActionLog::factory()->create([
            'user_id' => $user->id,
            'outcome' => $outcome,
            'reason' => $outcome === ActionLog::OUTCOME_FAILED ? 'Never came up' : null,
            'logged_at' => $at,
        ]);
    }

    public function test_an_empty_record_has_earned_nothing(): void
    {
        $this->assertSame(0, $this->wallet()->earnedFor(User::factory()->create()));
    }

    /** 3, then 2, then 1 and 1 — the floor repeats and nothing goes unpaid. */
    public function test_outcomes_taper_within_a_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->log($user, $this->base);
        $this->assertSame(3, $this->wallet()->earnedFor($user));

        $this->log($user, $this->base->addHour());
        $this->assertSame(5, $this->wallet()->earnedFor($user));

        $this->log($user, $this->base->addHours(2));
        $this->assertSame(6, $this->wallet()->earnedFor($user));

        $this->log($user, $this->base->addHours(3));
        $this->assertSame(7, $this->wallet()->earnedFor($user));
    }

    /** A new day resets the taper. */
    public function test_the_taper_restarts_the_next_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->log($user, $this->base);
        $this->log($user, $this->base->addDay());

        $this->assertSame(6, $this->wallet()->earnedFor($user));
    }

    /**
     * The catch-up case from F1 §3, stated as a number: seven days logged in
     * one sitting is one day of showing up, and pays 3+2+1+1+1+1+1.
     */
    public function test_a_catch_up_session_tapers_as_one_day(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 7; $index++) {
            $this->log($user, $this->base->addMinutes($index));
        }

        $this->assertSame(10, $this->wallet()->earnedFor($user));
    }

    /**
     * The day is the user's, not the server's. "The first outcome of the day"
     * is a claim about when they sat down.
     */
    public function test_the_day_is_read_in_the_users_own_timezone(): void
    {
        $late = User::factory()->create(['timezone' => 'Pacific/Auckland']);

        // Both of these land on 2 March in UTC, so grouping by the server's day
        // would taper them together and pay 3+2. Auckland is UTC+13 in March,
        // which puts the first at 23:00 on the 2nd and the second at 02:00 on
        // the 3rd — two separate evenings of sitting down, so two
        // first-of-the-day payments.
        $this->log($late, $this->base->setTime(10, 0));
        $this->log($late, $this->base->setTime(13, 0));

        $this->assertSame(6, $this->wallet()->earnedFor($late));

        // The same two moments for someone sitting in UTC are one day and pay
        // 3+2. Asserted here so this case cannot quietly stop discriminating:
        // if both numbers ever agree, the timezone is no longer being read.
        $server = User::factory()->create(['timezone' => 'UTC']);

        $this->log($server, $this->base->setTime(10, 0));
        $this->log($server, $this->base->setTime(13, 0));

        $this->assertSame(5, $this->wallet()->earnedFor($server));
    }

    /**
     * The therapeutic invariant, and not a detail. Paying differently for a
     * failure would build an incentive to hide failures and feel worst exactly
     * when the data matters most.
     */
    public function test_a_failed_outcome_pays_exactly_what_a_completed_one_pays(): void
    {
        $completions = User::factory()->create(['timezone' => 'UTC']);
        $failures = User::factory()->create(['timezone' => 'UTC']);
        $skips = User::factory()->create(['timezone' => 'UTC']);

        foreach ([
            [$completions, ActionLog::OUTCOME_COMPLETED],
            [$failures, ActionLog::OUTCOME_FAILED],
            [$skips, ActionLog::OUTCOME_SKIPPED],
        ] as [$user, $outcome]) {
            for ($index = 0; $index < 4; $index++) {
                $this->log($user, $this->base->addDays($index), $outcome);
            }
        }

        $this->assertSame(12, $this->wallet()->earnedFor($completions));
        $this->assertSame(
            $this->wallet()->earnedFor($completions),
            $this->wallet()->earnedFor($failures),
        );
        $this->assertSame(
            $this->wallet()->earnedFor($completions),
            $this->wallet()->earnedFor($skips),
        );
    }

    /**
     * A day mixing outcomes tapers by position and never by kind: the failure
     * that happens to be second pays 2 because it is second, not because it
     * failed.
     */
    public function test_a_mixed_day_tapers_by_position_and_not_by_outcome(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->log($user, $this->base, ActionLog::OUTCOME_FAILED);
        $this->log($user, $this->base->addHour(), ActionLog::OUTCOME_COMPLETED);
        $this->log($user, $this->base->addHours(2), ActionLog::OUTCOME_SKIPPED);

        $this->assertSame(6, $this->wallet()->earnedFor($user));
    }

    /** Each insight kind pays its configured value, outside the taper. */
    public function test_each_insight_kind_pays_its_own_rate(): void
    {
        $reflections = User::factory()->create();
        $loop = Intention::factory()->for($reflections)->create();

        // Three reflections within one hour. Outside the taper, so 15 — if they
        // were tapered this would be 3+2+1.
        for ($index = 0; $index < 3; $index++) {
            Summary::factory()->create([
                'user_id' => $reflections->id,
                'intention_id' => $loop->id,
                'scope' => Summary::SCOPE_INTENTION,
                'created_at' => $this->base->addMinutes($index),
                'updated_at' => $this->base->addMinutes($index),
            ]);
        }

        $this->assertSame(15, $this->wallet()->earnedFor($reflections));

        $concluded = User::factory()->create();
        Strategy::factory()
            ->for(Intention::factory()->for($concluded))
            ->create(['verdict' => Strategy::VERDICT_WORKED, 'verdict_note' => 'What the evidence showed.']);

        $this->assertSame(15, $this->wallet()->earnedFor($concluded));

        $corrected = User::factory()->create();
        Intention::factory()->for($corrected)->create([
            'metadata' => ['chain_revisions' => [['at' => $this->base->toIso8601String(), 'field' => 'cue']]],
        ]);

        $this->assertSame(8, $this->wallet()->earnedFor($corrected));

        $started = User::factory()->create();
        $theirLoop = Intention::factory()->for($started)->create();
        $first = Strategy::factory()->for($theirLoop)->create(['version' => 1]);
        Strategy::factory()->for($theirLoop)->create([
            'version' => 2,
            'parent_strategy_id' => $first->id,
        ]);

        $this->assertSame(10, $this->wallet()->earnedFor($started));
    }

    /** Outcomes and insights are added, not chosen between. */
    public function test_outcomes_and_insights_are_paid_together(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        $this->log($user, $this->base);
        $this->log($user, $this->base->addHour());

        Strategy::factory()
            ->for(Intention::factory()->for($user))
            ->create(['verdict' => Strategy::VERDICT_FAILED, 'verdict_note' => 'What the evidence showed.']);

        $this->assertSame(20, $this->wallet()->earnedFor($user));
    }

    /** Spending is the only stored half, and the balance is the difference. */
    public function test_the_balance_is_earned_less_spent(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 4; $index++) {
            $this->log($user, $this->base->addDays($index));
        }

        $this->assertSame(12, $this->wallet()->balanceFor($user));

        $user->companion()->firstOrCreate([])->update(['xp_spent' => 20]);

        $this->assertSame(0, $this->wallet()->balanceFor($user));
        $this->assertSame(20, $this->wallet()->spentFor($user));
        // Earned is untouched by spending: the two are separate readings.
        $this->assertSame(12, $this->wallet()->earnedFor($user));
    }

    /**
     * Deleting history lowers earned and therefore the balance. It is clamped
     * at zero, and — the part that matters — it takes nothing away: the skill
     * row is still there.
     */
    public function test_the_balance_clamps_at_zero_and_learned_skills_survive(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 4; $index++) {
            $this->log($user, $this->base->addDays($index));
        }

        $companion = $user->companion()->firstOrCreate([]);
        $companion->update(['xp_spent' => 12]);
        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);

        $this->assertSame(0, $this->wallet()->balanceFor($user));

        $user->actionLogs()->delete();

        $this->assertSame(0, $this->wallet()->earnedFor($user));
        $this->assertSame(0, $this->wallet()->balanceFor($user));
        $this->assertSame(1, $companion->fresh()->skills()->count());
    }

    /** A user with no companion row has spent nothing, rather than erroring. */
    public function test_a_user_with_no_companion_row_has_spent_nothing(): void
    {
        $user = User::factory()->create();

        $this->assertSame(0, $this->wallet()->spentFor($user));
        $this->assertSame(0, Companion::query()->count());
    }

    /** One person's record never pays another's Blob. */
    public function test_another_users_record_pays_nothing_here(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $stranger = User::factory()->create(['timezone' => 'UTC']);

        for ($index = 0; $index < 5; $index++) {
            $this->log($stranger, $this->base->addDays($index));
        }

        $this->assertSame(0, $this->wallet()->earnedFor($user));
    }
}
