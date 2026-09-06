<?php

namespace Tests\Feature\Workflows;

use App\Actions\LogAction;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use App\Services\Companion\CompanionResolver;
use App\Services\Workflows\MaterialisesOccasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Beginning to record on an action materialises the occasion the record hangs
 * on — and nothing else.
 *
 * A workflow record keys to an {@see Occurrence}, and a cue-anchored action
 * ("train after work") has no schedule, so it has produced none: today, logging
 * it is what creates one. But sets are ticked off during the session, long
 * before anyone presses Done or Missed, so the occasion has to exist first.
 *
 * The rule this whole file exists to pin: materialising must not create an
 * {@see ActionLog}. One occasion, one log, and the log is still a person
 * pressing a verdict afterwards. `logCount` is what the companion ladder
 * spends, so a module that could mint a log by being more granular than "one
 * occasion" would inflate the economy every other loop is measured against.
 */
class MaterialisesOccasionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    private function user(): User
    {
        return User::factory()->create(['timezone' => 'UTC']);
    }

    /**
     * A cue-anchored action. `anchored()` is what pins both of ActionFactory's
     * random fields — `recurrence` and `series_started_at` — to null, which is
     * what makes the action produce no grid of occasions at all.
     */
    private function anchoredAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->anchored()
            ->create();
    }

    /**
     * A scheduled action, with the factory's two random fields pinned
     * explicitly so the grid it stands for is the same on every run.
     */
    private function scheduledAction(User $user): Action
    {
        return Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => now()->subDays(5)->setTime(19, 0),
                'status' => Action::STATUS_ACTIVE,
            ]);
    }

    public function test_it_returns_the_due_occasion_when_one_exists(): void
    {
        $user = $this->user();
        $action = $this->scheduledAction($user);
        $due = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->setTime(19, 0),
        ]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        // Today's unlogged slot is the occasion this session belongs to.
        // Minting a fresh one stamped now would split the session off from the
        // occasion the verdict later lands on, and leave the real slot behind
        // on /catch-up forever.
        $this->assertTrue($occurrence->is($due));
        $this->assertSame(1, Occurrence::count());
    }

    public function test_it_returns_todays_slot_before_that_slot_is_due(): void
    {
        $this->travelTo('2026-08-26 18:00:00');

        $user = $this->user();
        $action = $this->scheduledAction($user);
        $tonight = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->setTime(19, 0),
        ]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        // Warming up at 18:00 for a session the grid puts at 19:00. Resolution
        // for a *verdict* is bounded to slots whose moment has passed — you do
        // not log a session you have not had — and a materialiser borrowing that
        // bound cannot see tonight's slot at all, so it mints a phantom 18:00
        // occasion beside it. Sets then hang off the phantom, and the real
        // 19:00 slot sits unlogged on /catch-up forever.
        //
        // Every other case in this file uses a cue-anchored action, which has no
        // grid and therefore no slot ahead of the clock to get this wrong about.
        $this->assertTrue($occurrence->is($tonight));
        $this->assertSame(1, Occurrence::count());
    }

    public function test_it_does_not_reach_into_tomorrow_for_a_slot(): void
    {
        $this->travelTo('2026-08-26 18:00:00');

        $user = $this->user();
        $action = $this->scheduledAction($user);
        $tomorrow = Occurrence::factory()->for($action)->create([
            'scheduled_for' => now()->addDay()->setTime(19, 0),
        ]);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        // "Due or not yet due" is bounded by the local day, not by the whole
        // future. A session started tonight belongs to tonight: filed against
        // tomorrow's slot it would be dated a day out, and tomorrow's slot would
        // arrive already answered.
        $this->assertFalse($occurrence->is($tomorrow));
        $this->assertSame('2026-08-26 18:00:00', $occurrence->scheduled_for->utc()->toDateTimeString());
        $this->assertSame(2, Occurrence::count());
    }

    public function test_the_verdict_lands_on_the_session_only_when_the_caller_names_it(): void
    {
        $this->travelTo('2026-08-26 18:00:00');

        $user = $this->user();

        $named = $this->scheduledAction($user);
        $namedSlot = Occurrence::factory()->for($named)->create([
            'scheduled_for' => now()->setTime(19, 0),
        ]);

        $unnamed = $this->scheduledAction($user);
        Occurrence::factory()->for($unnamed)->create([
            'scheduled_for' => now()->setTime(19, 0),
        ]);

        $namedSession = app(MaterialisesOccasion::class)->forAction($named);
        $unnamedSession = app(MaterialisesOccasion::class)->forAction($unnamed);

        // Both sessions begin on the 19:00 slot, an hour before it is due.
        $this->assertTrue($namedSession->is($namedSlot));

        // The contract a recording surface must honour: hand the occasion back
        // to LogAction. Its fourth parameter exists for precisely this.
        $namedLog = app(LogAction::class)->handle($user, $named, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $namedSession);

        // Omit it and the verdict resolves itself from scratch — against a slot
        // that is due, which at 18:00 the 19:00 one is not. This is not a bug in
        // LogAction; it is why passing the occasion is the contract rather than
        // a nicety, and it is pinned here so batch 2 finds out from a red test
        // rather than from a split record.
        $unnamedLog = app(LogAction::class)->handle($user, $unnamed, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ]);

        $this->assertSame($namedSession->id, $namedLog->occurrence_id);
        $this->assertNotSame($unnamedSession->id, $unnamedLog->occurrence_id);
    }

    public function test_it_creates_one_for_a_cue_anchored_action(): void
    {
        $user = $this->user();
        $action = $this->anchoredAction($user);

        $this->assertSame(0, Occurrence::count());

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        // A cue-anchored action has no grid, so there is nothing to resolve:
        // the occasion is stamped now and persisted, because a set recorded
        // against an unsaved occurrence has nothing to key to.
        $this->assertTrue($occurrence->exists);
        $this->assertSame(1, Occurrence::count());
        $this->assertSame($action->id, $occurrence->action_id);
        $this->assertSame('2026-08-26 21:00:00', $occurrence->scheduled_for->utc()->toDateTimeString());
    }

    public function test_two_calls_in_the_same_second_return_the_same_occasion(): void
    {
        $user = $this->user();
        $action = $this->anchoredAction($user);

        $materialises = app(MaterialisesOccasion::class);

        $first = $materialises->forAction($action);
        $second = $materialises->forAction($action);

        // Two taps on Start inside one second. Occasions are stored to the
        // second and unique on (action_id, scheduled_for), so a path that
        // minted rather than resolved would either split one session in two or
        // collide on the index.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Occurrence::count());
    }

    public function test_materialising_creates_no_log_and_moves_the_count_by_zero(): void
    {
        $user = $this->user();
        $action = $this->anchoredAction($user);

        $occurrence = app(MaterialisesOccasion::class)->forAction($action);

        // The occasion now exists to hang sets on. The verdict is still a
        // separate press, by a person, afterwards.
        $this->assertSame(0, ActionLog::count());

        // The same read through Blob's own resolver, which is the number the
        // ladder spends. The table count alone would pin the row; this pins the
        // economy.
        $this->assertSame(0, app(CompanionResolver::class)->forUser($user)->logCount);

        // And it still moves by zero against a record that is not empty: one
        // real verdict, pressed by a person, then a second session begun.
        app(LogAction::class)->handle($user, $action, [
            'outcome' => ActionLog::OUTCOME_COMPLETED,
        ], $occurrence);

        app(MaterialisesOccasion::class)->forAction($action);

        // The second session really did materialise — its own occasion, because
        // the first is answered — so the counts below are a delta of zero across
        // work that happened, not across a call that did nothing.
        $this->assertSame(2, Occurrence::count());
        $this->assertSame(1, ActionLog::count());
        $this->assertSame(1, app(CompanionResolver::class)->forUser($user)->logCount);
    }
}
