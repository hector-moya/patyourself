<?php

namespace Tests\Feature\Companion;

use App\Actions\LearnSkill;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use App\Services\Companion\CompanionResolver;
use App\Services\Companion\CompanionWallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Spending, which is the only thing about Blob the user chooses.
 *
 * The gift ladder is untouched by any of this — it keeps handing out the body
 * and the wearables from the record, unchosen and unpurchasable. A gift is
 * informational ("your record grew"); a purchase is transactional. Absorbing
 * one into the other would cost the one reward that is not a trade.
 */
class LearnSkillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    /**
     * Outcomes on `$days` separate days, so each pays the first-of-the-day 3
     * and the arithmetic in every case below is obvious.
     */
    private function richUser(int $days = 8): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $base = CarbonImmutable::parse('2026-04-01T09:00:00+00:00');

        for ($index = 0; $index < $days; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $base->addDays($index),
            ]);
        }

        return $user;
    }

    public function test_learning_a_skill_debits_the_balance_and_writes_the_row(): void
    {
        $user = $this->richUser();

        $this->assertSame(24, app(CompanionWallet::class)->balanceFor($user));

        $skill = app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertSame('gather-fibre', $skill->name);
        $this->assertNotNull($skill->learned_at);
        $this->assertSame(20, (int) $user->companion()->value('xp_spent'));
        $this->assertSame(4, app(CompanionWallet::class)->balanceFor($user));
    }

    /**
     * The row is the epoch for the node's stock, so the moment matters.
     *
     * Compared to the second rather than with `equalTo`: Eloquent's datetime
     * cast formats through `Y-m-d H:i:s` on the way in, so the column carries
     * no microseconds and a frozen `now()` does.
     */
    public function test_the_skill_records_when_it_was_learned(): void
    {
        $user = $this->richUser();

        $skill = app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertSame(
            now()->format('Y-m-d H:i:s'),
            $skill->learned_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_short_balance_is_refused_and_nothing_is_written(): void
    {
        $user = $this->richUser(2);

        $this->assertSame(6, app(CompanionWallet::class)->balanceFor($user));

        try {
            app(LearnSkill::class)->handle($user, 'gather-fibre');
            $this->fail('A short balance should have been refused.');
        } catch (CompanionEconomyException $exception) {
            $this->assertStringContainsString('gather-fibre', $exception->getMessage());
        }

        $this->assertDatabaseCount('companion_skills', 0);
        $this->assertSame(6, app(CompanionWallet::class)->balanceFor($user));
        $this->assertSame(0, (int) $user->companion()->value('xp_spent'));
    }

    /**
     * Exactly the price is enough. An off-by-one here would make the last
     * skill in a record unbuyable for no reason anyone could see.
     */
    public function test_a_balance_of_exactly_the_price_is_enough(): void
    {
        $user = $this->richUser();
        $user->companion()->firstOrCreate([])->update(['xp_spent' => 4]);

        $this->assertSame(20, app(CompanionWallet::class)->balanceFor($user));

        app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertSame(0, app(CompanionWallet::class)->balanceFor($user));
    }

    /**
     * Learning the same skill twice is not a second purchase. The unique index
     * would refuse it anyway; this makes the refusal quiet and free, because a
     * double-submitted click is not a user mistake.
     */
    public function test_learning_the_same_skill_again_costs_nothing(): void
    {
        $user = $this->richUser();

        $first = app(LearnSkill::class)->handle($user, 'gather-fibre');
        $second = app(LearnSkill::class)->handle($user, 'gather-fibre');

        $this->assertTrue($first->is($second));
        $this->assertSame(20, (int) $user->companion()->value('xp_spent'));
        $this->assertDatabaseCount('companion_skills', 1);
    }

    /**
     * The fork is real, and it is a fork rather than a wall: buying one never
     * blocks the other, it only decides which came first.
     */
    public function test_both_skills_can_be_bought_when_the_record_affords_both(): void
    {
        $user = $this->richUser(14);

        app(LearnSkill::class)->handle($user, 'gather-fibre');
        app(LearnSkill::class)->handle($user, 'gather-wood');

        $this->assertSame(40, (int) $user->companion()->value('xp_spent'));
        $this->assertSame(
            ['gather-fibre', 'gather-wood'],
            $user->companion->skills()->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_a_skill_nobody_authored_is_rejected(): void
    {
        $user = $this->richUser();

        $this->expectException(InvalidArgumentException::class);

        app(LearnSkill::class)->handle($user, 'gather-moonlight');
    }

    /** The debit and the row are one write or neither. */
    public function test_the_debit_and_the_row_are_written_together(): void
    {
        $user = $this->richUser();

        DB::beginTransaction();
        app(LearnSkill::class)->handle($user, 'gather-fibre');
        DB::rollBack();

        $this->assertDatabaseCount('companion_skills', 0);
        $this->assertSame(0, (int) $user->companion()->value('xp_spent'));
    }

    /** Buying a skill changes nothing about the gift ladder. */
    public function test_spending_never_touches_what_the_record_gave(): void
    {
        $user = $this->richUser();

        $before = app(CompanionResolver::class)->forUser($user)->toArray();

        app(LearnSkill::class)->handle($user, 'gather-fibre');

        $after = app(CompanionResolver::class)->forUser($user)->toArray();

        $this->assertEquals($before, $after);
    }
}
