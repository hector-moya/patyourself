<?php

namespace Tests\Feature\Companion;

use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanionTailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Gives the user enough insight events to satisfy the authored ladder and
     * then some. An insight is a concluded experiment, a started version, a
     * chain correction or a reflection — concluded experiments are the cheapest
     * to make in bulk.
     *
     * Also seeds enough logged outcomes to clear the three logs-triggered
     * rungs the ladder opens with (logs: 1, 3, 5) — the walk is sequential and
     * stops at the first unsatisfied entry, so no amount of insights matters
     * until those are cleared too.
     */
    private function userWithInsights(int $count): User
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        for ($i = 0; $i < 5; $i++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => now()->subDays(30 - $i),
            ]);
        }

        for ($i = 0; $i < $count; $i++) {
            Strategy::factory()->for($loop, 'intention')->create([
                'version' => $i + 1,
                'verdict' => 'worked',
            ]);
        }

        return $user;
    }

    public function test_the_ladder_continues_past_its_last_authored_rung(): void
    {
        $authored = count(config('companion.ladder'));
        $every = (int) config('companion.tail.every');

        // The last authored rung sits at insights: 9, so the first tail rung
        // needs `every` more than that.
        $user = $this->userWithInsights(9 + $every);

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertCount($authored + 1, $state->unlocks);
        $this->assertSame('item', $state->unlocks[$authored]['kind']);
        $this->assertNotNull($state->unlocks[$authored]['variant']);
    }

    public function test_a_tail_rung_is_identical_across_two_reads(): void
    {
        $user = $this->userWithInsights(9 + (int) config('companion.tail.every'));
        $resolver = app(CompanionResolver::class);

        $first = $resolver->forUser($user)->unlocks;
        $second = $resolver->forUser($user)->unlocks;

        // History cannot reword itself between two reads of the same record.
        $this->assertSame($first, $second);
    }

    public function test_the_tail_never_introduces_a_fifth_item_type(): void
    {
        $types = config('companion.item_types');
        $user = $this->userWithInsights(60);

        $state = app(CompanionResolver::class)->forUser($user);

        foreach ($state->unlocks as $unlock) {
            if ($unlock['kind'] === 'item') {
                $this->assertContains($unlock['name'], $types);
            }
        }
    }

    public function test_the_variant_palette_wraps_rather_than_running_out(): void
    {
        $variants = config('companion.tail.variants');
        $every = (int) config('companion.tail.every');

        // Enough rungs to walk past the end of the palette at least once.
        $user = $this->userWithInsights(9 + $every * (count($variants) + 2));

        $state = app(CompanionResolver::class)->forUser($user);
        $tail = array_slice($state->unlocks, count(config('companion.ladder')));

        $this->assertGreaterThan(count($variants), count($tail));
        foreach ($tail as $unlock) {
            $this->assertContains($unlock['variant'], $variants);
        }
    }

    /**
     * `count($insights) < 9` alone is not a scenario that exercises the guard
     * at CompanionResolver::tailUnlocks() — with too few logs to even clear
     * the ladder's first `insights`-triggered rung, the walk breaks on
     * arithmetic before the tail's own gate ever matters, and the test would
     * pass identically with that gate deleted. The record this actually has
     * to protect is one rich in insights and poor in logs: plenty of
     * concluded experiments, but the ladder's log-triggered rungs (logs: 1,
     * 3, 5) never cleared, so the authored walk stops at `logs: 3` having
     * granted only `blob`. Without the guard, 40 insight events would still
     * satisfy the tail's own arithmetic and hand a Blob with no legs ten
     * recoloured scarves.
     */
    public function test_the_tail_does_not_start_while_the_authored_ladder_is_still_open(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();

        // Clears `logs: 1` (blob) but not `logs: 3` (legs) — the walk stops
        // there regardless of how many insights follow.
        for ($i = 0; $i < 2; $i++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => now()->subDays(30 - $i),
            ]);
        }

        for ($i = 0; $i < 40; $i++) {
            Strategy::factory()->for($loop, 'intention')->create([
                'version' => $i + 1,
                'verdict' => 'worked',
            ]);
        }

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertCount(1, $state->unlocks);
    }

    public function test_an_absent_tail_block_ends_the_ladder_where_it_ends_today(): void
    {
        config(['companion.tail' => []]);
        $user = $this->userWithInsights(60);

        $state = app(CompanionResolver::class)->forUser($user);

        $this->assertCount(count(config('companion.ladder')), $state->unlocks);
    }

    public function test_a_room_object_arrives_on_the_tail_at_its_own_cadence(): void
    {
        $every = (int) config('companion.tail.every');
        $roomEvery = (int) config('companion.tail.room_every');

        $user = $this->userWithInsights(9 + $every * $roomEvery);

        $state = app(CompanionResolver::class)->forUser($user);
        $tail = array_slice($state->unlocks, count(config('companion.ladder')));

        $this->assertNotNull(end($tail)['room_object']);
        // And the rungs before it did not each bring one.
        $this->assertNull($tail[0]['room_object']);
    }
}
