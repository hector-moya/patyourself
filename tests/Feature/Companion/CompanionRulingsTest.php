<?php

namespace Tests\Feature\Companion;

use App\Actions\ConcludeExperiment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three things F2 refuses, each written as a test that fails if the
 * refusal is ever quietly reversed.
 *
 * None of these asserts a feature. They assert the ABSENCE of one, which is
 * why they exist: a ruling recorded only in prose erodes, and each of these
 * three is the kind a later phase could undo while meaning to do something
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

        // And the world only ever grew.
        $this->assertGreaterThan(3, $this->standing($fresh, 'reeds'));
    }
}
