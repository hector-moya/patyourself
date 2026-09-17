<?php

namespace Tests\Feature\Companion;

use App\Actions\LogAction;
use App\Events\ActionLogged;
use App\Listeners\StockCompanionNodes;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Companion;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The world is stocked by the record, not by the clock.
 *
 * Each outcome recorded adds one unit to each node whose skill Blob has
 * learned. Nothing grows while you are away and nothing expires — the world
 * simply holds whatever you could not carry. (F1 §4)
 */
class StockCompanionNodesTest extends TestCase
{
    use RefreshDatabase;

    /** Wired by discovery rather than by a provider — assert it, do not assume. */
    public function test_the_listener_is_wired_to_the_event(): void
    {
        Event::fake();

        Event::assertListening(ActionLogged::class, StockCompanionNodes::class);
    }

    private function actionFor(User $user): Action
    {
        $loop = Intention::factory()->for($user)->create();

        return Action::factory()
            ->for($loop)
            ->for(Strategy::factory()->for($loop))
            ->create();
    }

    /**
     * Occasions are unique per (action, scheduled_for), so each one this class
     * logs needs its own minute.
     */
    private int $occasion = 0;

    /**
     * One outcome, on its own occasion so nothing has to resolve a live slot.
     */
    private function logOnce(User $user, Action $action): void
    {
        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->subMinutes(++$this->occasion),
        ]);

        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);
    }

    private function learn(Companion $companion, string $skill): void
    {
        $companion->skills()->create(['name' => $skill, 'learned_at' => now()]);
    }

    private function standing(Companion $companion, string $node): int
    {
        return (int) $companion->nodes()->where('node', $node)->value('available');
    }

    public function test_an_outcome_adds_one_unit_to_each_unlocked_node(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');
        $this->learn($companion, 'gather-wood');

        $action = $this->actionFor($user);

        $this->logOnce($user, $action);
        $this->logOnce($user, $action);

        $this->assertSame(2, $this->standing($companion, 'reeds'));
        $this->assertSame(2, $this->standing($companion, 'deadfall'));
    }

    /** A node whose skill is not learned gets nothing, met or not. */
    public function test_a_node_without_its_skill_gets_nothing(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');

        // Met but unlearned: the row exists at zero and must stay there. This
        // is the case that separates "Blob has seen this" from "Blob can use
        // this" — the encounter writes a row, the skill fills it.
        $companion->nodes()->create(['node' => 'deadfall', 'available' => 0]);

        $this->logOnce($user, $this->actionFor($user));

        $this->assertSame(1, $this->standing($companion, 'reeds'));
        $this->assertSame(0, $this->standing($companion, 'deadfall'));
    }

    /**
     * The retroactivity rule, and the reason the whole thing is a listener.
     *
     * XP is retroactive over the whole record, deliberately — the record is
     * real and counts. The world is not, equally deliberately: an established
     * account that learns a skill today arrives to an empty clearing rather
     * than one holding a unit for every outcome it ever logged.
     */
    public function test_an_established_record_starts_at_an_empty_clearing(): void
    {
        $user = User::factory()->create();
        $action = $this->actionFor($user);

        for ($index = 0; $index < 20; $index++) {
            $this->logOnce($user, $action);
        }

        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');

        $this->assertSame(0, $this->standing($companion, 'reeds'));
        $this->assertDatabaseCount('companion_nodes', 0);

        $this->logOnce($user, $action);

        $this->assertSame(1, $this->standing($companion, 'reeds'));
    }

    /**
     * The companion is looked up fresh, not read off the user instance.
     *
     * Eloquent caches a lazily-loaded relation on the model, INCLUDING when it
     * resolves to null. So a user object asked for its companion before the row
     * existed keeps answering null for the rest of the request, and the
     * stocking silently does nothing. The same `$user` is reused throughout
     * here on purpose — that is the whole test.
     */
    public function test_the_companion_is_looked_up_fresh_on_every_event(): void
    {
        $user = User::factory()->create();
        $action = $this->actionFor($user);

        // Poisons the relation cache with null, exactly as a first log would.
        $this->logOnce($user, $action);
        $this->assertNull($user->companion);

        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');

        $this->logOnce($user, $action);

        $this->assertSame(1, $this->standing($companion, 'reeds'));
    }

    /** Whatever the outcome was. Honest logging is the behaviour being paid. */
    public function test_every_outcome_stocks_the_world_equally(): void
    {
        foreach (ActionLog::OUTCOMES as $outcome) {
            $user = User::factory()->create();
            $companion = $user->companion()->firstOrCreate([]);
            $this->learn($companion, 'gather-fibre');

            $action = $this->actionFor($user);
            $occurrence = Occurrence::factory()->for($action)->create([
                'scheduled_for' => now()->subMinutes(++$this->occasion),
            ]);

            app(LogAction::class)->handle($user, $action, [
                'outcome' => $outcome,
                'reason' => $outcome === ActionLog::OUTCOME_FAILED ? 'Never came up' : null,
            ], $occurrence);

            $this->assertSame(1, $this->standing($companion, 'reeds'), "a {$outcome} outcome should stock");
        }
    }

    /** A user with no companion row is not given one by logging. */
    public function test_logging_alone_does_not_create_a_companion(): void
    {
        $user = User::factory()->create();

        $this->logOnce($user, $this->actionFor($user));

        $this->assertDatabaseCount('companions', 0);
        $this->assertDatabaseCount('companion_nodes', 0);
    }

    /** Insights do not stock the world in F1; whether they should is an F2 question. */
    public function test_an_insight_does_not_stock_the_world(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');

        Strategy::factory()
            ->for(Intention::factory()->for($user))
            ->create(['verdict' => Strategy::VERDICT_WORKED, 'verdict_note' => 'What the evidence showed.']);

        $this->assertSame(0, $this->standing($companion, 'reeds'));
    }

    /** One person's logging never stocks another's clearing. */
    public function test_another_users_record_stocks_nothing_here(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');

        $stranger = User::factory()->create();
        $strangerCompanion = $stranger->companion()->firstOrCreate([]);
        $this->learn($strangerCompanion, 'gather-fibre');

        $this->logOnce($stranger, $this->actionFor($stranger));

        $this->assertSame(0, $this->standing($companion, 'reeds'));
        $this->assertSame(1, $this->standing($strangerCompanion, 'reeds'));
    }

    /** Stock is uncapped: the world holds whatever you cannot carry. */
    public function test_stock_is_uncapped_and_ignores_the_bag_being_full(): void
    {
        $user = User::factory()->create();
        $companion = $user->companion()->firstOrCreate([]);
        $this->learn($companion, 'gather-fibre');

        // Hands full — base capacity is 5 and all of it is taken.
        $companion->items()->create(['item' => 'fibre', 'quantity' => 5]);

        $action = $this->actionFor($user);

        for ($index = 0; $index < 9; $index++) {
            $this->logOnce($user, $action);
        }

        $this->assertSame(9, $this->standing($companion, 'reeds'));
    }
}
