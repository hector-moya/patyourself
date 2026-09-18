<?php

namespace Tests\Feature\Companion;

use App\Actions\BuildItem;
use App\Actions\ConcludeExperiment;
use App\Actions\DropItem;
use App\Actions\HarvestNode;
use App\Actions\LogAction;
use App\Actions\UpdateIntention;
use App\Actions\WriteReflection;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Companion\CompanionEconomyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The things F2 and F3 refuse, each written as a test that fails if the
 * refusal is ever quietly reversed.
 *
 * None of these asserts a feature. They assert the ABSENCE of one, which is
 * why they exist: a ruling recorded only in prose erodes, and each of these
 * is the kind a later phase could undo while meaning to do something
 * else entirely.
 */
class CompanionRulingsTest extends TestCase
{
    use RefreshDatabase;

    private int $occasion = 0;

    private function clearing(): array
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);

        $companion->skills()->create(['name' => 'gather-fibre', 'learned_at' => now()]);
        $companion->nodes()->create(['node' => 'reeds', 'available' => 3]);

        return [$user, $companion];
    }

    private function logOnce(User $user): void
    {
        $loop = Intention::factory()->for($user)->create();

        $action = Action::factory()
            ->for($loop)
            ->for(Strategy::factory()->for($loop))
            ->create();

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subMinutes(++$this->occasion),
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);
    }

    private function standing(Companion $companion, string $node): int
    {
        return (int) $companion->nodes()->where('node', $node)->value('available');
    }

    /**
     * There is no insight event in this application: the four kinds are
     * DERIVED from database state by CompanionResolver::insightMoments(), with
     * no seam to listen to. Stocking from them would mean new domain events
     * and a second opinion about what happened, alongside a resolver that
     * already derives it — architecture for a pacing adjustment the world does
     * not need, now that it accrues across three nodes.
     *
     * XP is where insights are paid, generously and outside the taper. This is
     * the guard that keeps the ruling from being undone by accident.
     */
    public function test_insight_events_do_not_stock_the_world(): void
    {
        [$user, $companion] = $this->clearing();

        $before = $this->standing($companion, 'reeds');

        $loop = Intention::factory()->for($user)->create(['craving' => 'To feel less tired']);

        // A reflection written.
        app(WriteReflection::class)->handle($loop, 'Still reads as a cue problem.');

        // A loop's chain corrected.
        app(UpdateIntention::class)->handle($loop, ['craving' => 'To stop thinking about work']);

        // An experiment concluded.
        $strategy = Strategy::factory()->for($loop)->create(['version' => 1]);
        app(ConcludeExperiment::class)->handle($strategy, Strategy::VERDICT_WORKED, 'What the evidence showed.');

        // A new strategy version started, written as rows because that is what
        // the resolver reads — a child strategy with a parent.
        Strategy::factory()->for($loop)->create([
            'version' => 2,
            'parent_strategy_id' => $strategy->id,
        ]);

        $this->assertSame($before, $this->standing($companion->fresh(), 'reeds'));
    }

    /**
     * A tool is on the belt. It is permanent, so a tool that occupied a slot
     * would be a permanent tax — gaining the axe would shrink the bag forever,
     * which is regression in everything but name.
     */
    public function test_a_tool_takes_up_no_room(): void
    {
        [, $companion] = $this->clearing();

        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $fresh = $companion->fresh()->load('items');

        $this->assertSame(2, $fresh->held());
        $this->assertSame(5, $fresh->capacity());

        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);

        $withTheAxe = $companion->fresh()->load('items');

        // The axe changed nothing about what can be carried, in either
        // direction. It neither costs room nor creates it.
        $this->assertSame(2, $withTheAxe->held());
        $this->assertSame(5, $withTheAxe->capacity());
        $this->assertSame(3, $withTheAxe->room());
    }

    /**
     * Nothing wears out and nothing is removed for inactivity. The only thing
     * in this feature that ever reduces a quantity is a recipe consuming it,
     * and this walks a week of the record past a bag to say so.
     */
    public function test_nothing_is_taken_by_the_passage_of_the_record(): void
    {
        [$user, $companion] = $this->clearing();

        $companion->items()->create(['item' => 'axe', 'quantity' => 1]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 4]);

        for ($index = 0; $index < 7; $index++) {
            $this->logOnce($user);
        }

        $fresh = $companion->fresh();

        $this->assertSame(1, (int) $fresh->items()->where('item', 'axe')->value('quantity'));
        $this->assertSame(4, (int) $fresh->items()->where('item', 'fibre')->value('quantity'));

        // And the world only ever grew: 3 seeded plus one per logged outcome
        // against the one learned skill, seven times over, is exactly 10.
        $this->assertSame(10, $this->standing($fresh, 'reeds'));
    }

    /**
     * Spec §2's second overturn, guarded from the side that matters.
     *
     * F3 adds a destruction, and the whole justification is that the PLAYER
     * initiates it. The line that must hold is therefore not "nothing is
     * destroyed" — that one is genuinely overturned — but that nothing in this
     * feature ever drops, decays, expires or discards on the player's behalf,
     * for any reason, INCLUDING A FULL BAG.
     *
     * A full bag is the case worth driving, because it is the one place where
     * a later phase might "helpfully" make room. Here the bag is full, more is
     * standing than will fit, and a week of the record goes past: the harvest
     * refuses, the build refuses, and every quantity is exactly what it was.
     */
    public function test_nothing_is_dropped_on_the_players_behalf(): void
    {
        [$user, $companion] = $this->clearing();

        // Full to the brim, and holding something with no single-material
        // exit — the exact shape of the trap F3 exists to defuse.
        $companion->items()->create(['item' => 'timber', 'quantity' => 5]);
        $companion->nodes()->updateOrCreate(['node' => 'reeds'], ['available' => 9]);

        $before = $companion->fresh()->items->pluck('quantity', 'item')->all();

        for ($index = 0; $index < 7; $index++) {
            $this->logOnce($user);
        }

        // A harvest into a bag with no room refuses, and refuses WHOLE.
        try {
            app(HarvestNode::class)->handle($user, 'reeds');
            $this->fail('a full bag should refuse rather than make room');
        } catch (CompanionEconomyException) {
            // The refusal is the expected outcome; what matters is below.
        }

        // So does a build that cannot fit what it would make.
        try {
            app(BuildItem::class)->handle($user, 'basket');
            $this->fail('a recipe short of materials should refuse');
        } catch (CompanionEconomyException) {
            // Likewise.
        }

        $after = $companion->fresh()->items->pluck('quantity', 'item')->all();

        $this->assertSame($before, $after, 'something was taken that nobody asked to lose');

        // And the world only ever grew: 3 seeded plus one per logged outcome
        // against the one learned skill, seven times over.
        $this->assertSame(16, $this->standing($companion->fresh(), 'reeds'));
    }

    /**
     * The other half of the same rule: when the player DOES ask, exactly what
     * they asked for goes and nothing else does — including at the node the
     * material came from, which is not a place things go back to.
     */
    public function test_a_drop_takes_exactly_what_was_dropped_and_nothing_else(): void
    {
        [$user, $companion] = $this->clearing();

        $companion->items()->create(['item' => 'timber', 'quantity' => 3]);
        $companion->items()->create(['item' => 'fibre', 'quantity' => 2]);

        $standingBefore = $this->standing($companion, 'reeds');

        app(DropItem::class)->handle($user, 'timber');

        $fresh = $companion->fresh();

        $this->assertSame(0, $fresh->items()->where('item', 'timber')->count());
        $this->assertSame(2, (int) $fresh->items()->where('item', 'fibre')->value('quantity'));
        $this->assertSame($standingBefore, $this->standing($fresh, 'reeds'));
    }
}
