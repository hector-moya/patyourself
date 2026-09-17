<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildItem;
use App\Actions\HarvestNode;
use App\Actions\LearnSkill;
use App\Actions\MeetNode;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F2's whole chain, walked once, on the real shipped content.
 *
 * Every gate this phase added had only ever been exercised against a
 * synthetic `['fibre' => 1]` stand-in with the `tool` key stripped off — six
 * times for `planks`, once for `axe` — or not exercised at all: `handsaw` and
 * `crate` were built zero times, and `HarvestNode` was never called on
 * `trunk`. The shipped chain had been run only by the controller's manual
 * probes. This is that chain, end to end, with no override in sight:
 *
 *     3 fibre  -> rope
 *     2 deadfall + 1 rope -> axe
 *     learn chop-wood, harvest the trunk -> timber
 *     2 timber + 1 rope -> handsaw
 *     1 timber + handsaw -> 3 planks
 *     4 planks -> crate
 *
 * A single `planks` build falls one short of what `crate` asks for — three
 * made, four needed — so that step happens twice here, which is itself
 * content nobody had written down before this test walked the numbers.
 *
 * Gathering fibre and deadfall, and stocking the trunk, are F1 machinery
 * already covered elsewhere (HarvestNodeTest, StockCompanionNodesTest); those
 * are seeded directly so this stays about what F2 shipped: the tool gate on a
 * node, the tool gate on a recipe, and a recipe that makes several. Every
 * other step goes through the real Action.
 */
class CompanionToolChainTest extends TestCase
{
    use RefreshDatabase;

    /** Enough outcomes, on enough separate days, to clear chop-wood's price. */
    private function richUser(): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $base = CarbonImmutable::parse('2026-05-01T09:00:00+00:00');

        for ($index = 0; $index < 8; $index++) {
            ActionLog::factory()->create([
                'user_id' => $user->id,
                'outcome' => ActionLog::OUTCOME_COMPLETED,
                'logged_at' => $base->addDays($index),
            ]);
        }

        return $user;
    }

    private function held(Companion $companion, string $item): int
    {
        return (int) $companion->fresh()->items()->where('item', $item)->value('quantity');
    }

    public function test_the_whole_chain_builds_end_to_end_on_real_content(): void
    {
        // The chain does not fit in the base 5-slot bag: the second `planks`
        // build below asks for room for a net +2 (three made, one consumed)
        // with only one slot free at that point (four held of five). That is
        // the exact moment the phase's own capacity would refuse this chain,
        // so capacity is raised by exactly the one slot that closes the gap —
        // not to some generous headroom, which would hide that the chain
        // overflows the base bag at all. Granted directly rather than solved
        // by building an early basket or barrow, which this chain has no
        // other reason to want.
        config()->set('companion.capacity.base', 6);

        $user = $this->richUser();
        $companion = $user->companion()->firstOrCreate([]);

        // 3 fibre -> rope.
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);
        app(BuildItem::class)->handle($user, 'rope');

        $this->assertSame(1, $this->held($companion, 'rope'));
        $this->assertSame(0, $this->held($companion, 'fibre'));

        // 2 deadfall + 1 rope -> axe.
        $companion->items()->create(['item' => 'deadfall', 'quantity' => 2]);
        app(BuildItem::class)->handle($user, 'axe');

        $this->assertSame(1, $this->held($companion, 'axe'));
        $this->assertSame(0, $this->held($companion, 'rope'));
        $this->assertSame(0, $this->held($companion, 'deadfall'));

        // Learn chop-wood, meet the trunk, harvest it. What stocks a node
        // over time is StockCompanionNodes, not this action, so the stand
        // is seeded directly — the same reason the fibre and deadfall above
        // were.
        app(LearnSkill::class)->handle($user, 'chop-wood');
        app(MeetNode::class)->handle($user, 'trunk');
        $companion->nodes()->where('node', 'trunk')->update(['available' => 4]);

        $moved = app(HarvestNode::class)->handle($user, 'trunk');

        $this->assertSame(4, $moved);
        $this->assertSame(4, $this->held($companion, 'timber'));

        // 2 timber + 1 rope -> handsaw. A second rope, because the first was
        // spent on the axe.
        $companion->items()->create(['item' => 'fibre', 'quantity' => 3]);
        app(BuildItem::class)->handle($user, 'rope');
        app(BuildItem::class)->handle($user, 'handsaw');

        $this->assertSame(1, $this->held($companion, 'handsaw'));
        $this->assertSame(2, $this->held($companion, 'timber'));
        $this->assertSame(0, $this->held($companion, 'rope'));

        // 1 timber + handsaw -> 3 planks, twice: one build (three planks)
        // falls one short of the crate's four.
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(6, $this->held($companion, 'planks'));
        $this->assertSame(0, $this->held($companion, 'timber'));

        // 4 planks -> crate.
        app(BuildItem::class)->handle($user, 'crate');

        $this->assertSame(1, $this->held($companion, 'crate'));
        $this->assertSame(2, $this->held($companion, 'planks'));

        // The handsaw and the axe were used throughout and never used up.
        $this->assertSame(1, $this->held($companion, 'axe'));
        $this->assertSame(1, $this->held($companion, 'handsaw'));
    }
}
