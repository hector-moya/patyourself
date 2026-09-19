<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildItem;
use App\Actions\BuildShelter;
use App\Actions\HarvestNode;
use App\Actions\LearnSkill;
use App\Actions\LogAction;
use App\Actions\MeetNode;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole economy, played from an empty record to a finished cabin, on the
 * content that actually ships.
 *
 * NOTHING HERE WRITES TO THE COMPANION'S TABLES DIRECTLY and nothing overrides
 * a price or a capacity. Every unit of every material is logged for, gathered
 * and built with, through the same actions a press on the screen reaches. That
 * restriction is the entire value of the test: F2 was verified mechanism by
 * mechanism and never once played, and both of its worst findings lived in the
 * gap that left — a `buildable` that promised a build the action refused, and a
 * bag of timber with no way out of it.
 *
 * What it pins, beyond "it can be done at all":
 *
 *   - The cabin is consumed in ONE act of fourteen planks, so the bag must
 *     hold fourteen. The base bag holds five. TWO CONTAINERS ARE THEREFORE
 *     REQUIRED before a cabin is reachable, and this walk builds them out of
 *     gathered fibre and deadfall rather than assuming them.
 *   - Sawing is net +2 on a bag, so every plank run has to be sequenced
 *     against what is already in there.
 *   - A failed outcome pays exactly what a completed one pays, which is why
 *     the record below is deliberately a mixture.
 */
class CompanionEconomyWalkTest extends TestCase
{
    use RefreshDatabase;

    private Action $action;

    private int $occasion = 0;

    /**
     * One loop with one action, so that logging many outcomes does not mean
     * creating many loops — which would quietly test a different thing, since
     * breadth is exactly what the taper exists not to reward.
     */
    private function aLoopToLog(User $user): void
    {
        $loop = Intention::factory()->for($user)->create();

        $this->action = Action::factory()
            ->for($loop)
            ->for(Strategy::factory()->for($loop))
            ->create();
    }

    /** `$days` days of the record, `$each` outcomes on each of them. */
    private function log(User $user, int $days, int $each): void
    {
        for ($day = 0; $day < $days; $day++) {
            for ($index = 0; $index < $each; $index++) {
                $occurrence = Occurrence::factory()->for($this->action)->create([
                    'scheduled_for' => now()->subMinutes(++$this->occasion),
                ]);

                app(LogAction::class)->handle($user, $this->action, [
                    // Deliberately mixed. A failure advances Blob exactly as
                    // far as a completion, and a walk that only ever logged
                    // successes would not be exercising the thing this whole
                    // feature is built on.
                    'outcome' => $index % 2 === 0
                        ? ActionLog::OUTCOME_COMPLETED
                        : ActionLog::OUTCOME_FAILED,
                ], $occurrence);
            }

            $this->travel(1)->days();
        }
    }

    /** Five concluded experiments: the cabin's floor, and nothing above it. */
    private function reachTheCabinFloor(User $user): void
    {
        for ($index = 0; $index < 5; $index++) {
            Strategy::factory()
                ->for(Intention::factory()->for($user))
                ->create([
                    'verdict' => Strategy::VERDICT_WORKED,
                    'verdict_note' => 'What the evidence showed.',
                ]);
        }
    }

    private function held(User $user, string $item): int
    {
        return (int) $user->companion()->first()?->items()
            ->where('item', $item)
            ->value('quantity');
    }

    private function capacity(User $user): int
    {
        return $user->companion()->first()->fresh()->load('items')->capacity();
    }

    public function test_an_empty_record_can_reach_a_finished_cabin(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->aLoopToLog($user);

        // ---- The record pays for the skills -------------------------------
        //
        // Twelve days of four outcomes each. The taper pays 3 + 2 + 1 + 1 on a
        // day, so this is 12 x 7 = 84 xp — enough for three 20 xp skills with
        // room to spare, and it takes twelve days rather than one because the
        // taper is what stops breadth out-earning depth.
        $this->log($user, 12, 4);

        $this->assertGreaterThanOrEqual(60, app(CompanionBag::class)->forUser($user)['xp']);

        // Meeting a node is what puts its skill on the list. Nothing is
        // buyable before it has been seen.
        app(MeetNode::class)->handle($user, 'reeds');
        app(MeetNode::class)->handle($user, 'deadfall');
        app(MeetNode::class)->handle($user, 'trunk');

        app(LearnSkill::class)->handle($user, 'gather-fibre');
        app(LearnSkill::class)->handle($user, 'gather-wood');
        app(LearnSkill::class)->handle($user, 'chop-wood');

        // ---- The record stocks the world ----------------------------------
        //
        // And only from here: stock accrues from the moment a skill is learned,
        // so the twelve days above put nothing in the clearing. Thirty more
        // outcomes is thirty units at each of the three nodes, comfortably
        // above the eleven timber, ten fibre and six deadfall the walk needs.
        $this->log($user, 10, 3);

        // ---- Rope, then the axe -------------------------------------------
        //
        // Capacity is five. Every step below is sequenced against it, and
        // nothing overrides it.
        app(HarvestNode::class)->handle($user, 'reeds', 3);
        app(BuildItem::class)->handle($user, 'rope');

        app(HarvestNode::class)->handle($user, 'deadfall', 2);
        app(BuildItem::class)->handle($user, 'axe');

        $this->assertSame(1, $this->held($user, 'axe'));
        $this->assertSame(0, $user->companion()->first()->fresh()->load('items')->held());

        // ---- Two containers, which the cabin makes compulsory --------------
        app(HarvestNode::class)->handle($user, 'reeds', 4);
        app(BuildItem::class)->handle($user, 'basket');

        $this->assertSame(10, $this->capacity($user));

        app(HarvestNode::class)->handle($user, 'deadfall', 4);
        app(BuildItem::class)->handle($user, 'barrow');

        $this->assertSame(15, $this->capacity($user));

        // ---- The handsaw, which needs the trunk, which needs the axe ------
        app(HarvestNode::class)->handle($user, 'reeds', 3);
        app(BuildItem::class)->handle($user, 'rope');

        app(HarvestNode::class)->handle($user, 'trunk', 2);
        app(BuildItem::class)->handle($user, 'handsaw');

        $this->assertSame(1, $this->held($user, 'handsaw'));

        // ---- The lean-to: two timber, sawn twice, is six planks ------------
        app(HarvestNode::class)->handle($user, 'trunk', 2);
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(6, $this->held($user, 'planks'));

        app(BuildShelter::class)->handle($user, 'lean-to');

        $this->assertSame('lean-to', $user->companion()->first()->fresh()->shelter);
        $this->assertSame(2, $this->held($user, 'planks'));

        // ---- The hut: three more timber is nine more planks ---------------
        app(HarvestNode::class)->handle($user, 'trunk', 3);
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');
        app(BuildItem::class)->handle($user, 'planks');

        $this->assertSame(11, $this->held($user, 'planks'));

        app(BuildShelter::class)->handle($user, 'hut');

        $this->assertSame('hut', $user->companion()->first()->fresh()->shelter);

        // ---- The cabin: fourteen planks held at once, in a bag of fifteen --
        //
        // This is the step the base bag cannot do, and the reason two
        // containers are not optional.
        app(HarvestNode::class)->handle($user, 'trunk', 4);

        for ($saw = 0; $saw < 4; $saw++) {
            app(BuildItem::class)->handle($user, 'planks');
        }

        $this->assertSame(15, $this->held($user, 'planks'));

        $this->reachTheCabinFloor($user);

        app(BuildShelter::class)->handle($user, 'cabin');

        $companion = $user->companion()->first()->fresh();

        $this->assertSame('cabin', $companion->shelter);
        $this->assertSame(1, $this->held($user, 'planks'));

        // Nothing was taken along the way that was not spent on something:
        // both tools are still on the belt and both containers are still
        // holding the bag open.
        $this->assertSame(1, $this->held($user, 'axe'));
        $this->assertSame(1, $this->held($user, 'handsaw'));
        $this->assertSame(15, $this->capacity($user));
    }

    /**
     * The one claim the walk above cannot make on its own: that the base bag
     * really is too small, so the two containers were necessary rather than
     * incidental.
     *
     * Stated as a test because it is a fact about the CONTENT that a later
     * tuning pass could change without noticing — and if it ever stops being
     * true, the walk above would still pass while meaning something else.
     */
    public function test_the_cabin_cannot_be_held_by_the_bag_alone(): void
    {
        $cabin = (int) array_sum(config('companion.shelter.cabin.recipe'));

        $this->assertGreaterThan((int) config('companion.capacity.base'), $cabin);
    }

    /**
     * And the screen agrees with the actions at the end of it: the bag says
     * the cabin is standing, offers nothing after it, and has stopped
     * offering the stages that are already up.
     *
     * This writes `shelter` directly, which the rest of the file forbids. That
     * is deliberate and confined — it is asserting what the BAG SAYS about an
     * end state, not how the end state was reached, and reaching it honestly
     * would mean repeating the whole walk above.
     */
    public function test_the_bag_agrees_with_what_was_built(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $this->aLoopToLog($user);
        $this->log($user, 2, 1);

        app(MeetNode::class)->handle($user, 'trunk');
        app(MeetNode::class)->handle($user, 'reeds');

        /** @var Companion $companion */
        $companion = $user->companion()->first();
        $companion->update(['shelter' => 'cabin']);

        $shelter = app(CompanionBag::class)->forUser($user)['shelter'];

        $this->assertSame('cabin', $shelter['built']);
        $this->assertNull($shelter['offer']);
    }
}
