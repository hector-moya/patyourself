<?php

namespace Tests\Feature\Actions;

use App\Actions\ArchiveAction;
use App\Actions\CreateAction;
use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Authoring\AuthoredAction;
use App\Services\Scheduling\MaterialiseOccurrences;
use App\Services\Strategy\StrategyTransitionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two writers that let a loop's action layer change after creation.
 * Archiving is the load-bearing one: occurrences hang off an action and
 * outcomes hang off occurrences, so removing an action must never take the
 * evidence with it.
 */
class ActionWritersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-26 21:00:00');
    }

    private function loopWithActiveStrategy(User $user): Intention
    {
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create(['version' => 1, 'status' => Strategy::STATUS_ACTIVE]);

        return $loop->fresh();
    }

    private function clockAction(string $time = '19:00', string $recurrence = 'daily'): AuthoredAction
    {
        return new AuthoredAction(
            title: 'Put the pan back on the stove before sitting down',
            description: 'Out of reach, so a second plate takes a decision',
            kind: 'clock',
            time: $time,
            recurrence: $recurrence,
            anchor: null,
        );
    }

    public function test_a_clock_action_is_scheduled_and_anchored_at_the_same_moment(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveStrategy($user);

        $action = app(CreateAction::class)->handle($loop, $this->clockAction());

        $this->assertNotNull($action->scheduled_for);
        $this->assertTrue($action->scheduled_for->isFuture());
        $this->assertTrue($action->series_started_at->equalTo($action->scheduled_for));
        $this->assertSame('daily', $action->recurrence);
        $this->assertSame(Action::STATUS_PENDING, $action->status);
        $this->assertSame('clock', $action->metadata['schedule_kind']);
    }

    public function test_a_cue_anchored_action_carries_its_phrase_and_no_schedule(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveStrategy($user);

        $action = app(CreateAction::class)->handle($loop, new AuthoredAction(
            title: 'Name the craving out loud',
            description: null,
            kind: 'anchored',
            time: null,
            recurrence: null,
            anchor: 'after serving the first plate',
        ));

        $this->assertNull($action->scheduled_for);
        $this->assertNull($action->series_started_at);
        $this->assertSame('anchored', $action->metadata['schedule_kind']);
        $this->assertSame('after serving the first plate', $action->metadata['anchor']);
    }

    public function test_it_binds_the_action_to_the_loops_active_strategy(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();
        Strategy::factory()->for($loop)->create(['version' => 1, 'status' => Strategy::STATUS_SUPERSEDED]);
        $active = Strategy::factory()->for($loop)->create(['version' => 2, 'status' => Strategy::STATUS_ACTIVE]);

        $action = app(CreateAction::class)->handle($loop->fresh(), $this->clockAction());

        $this->assertSame($active->id, $action->strategy_id);
    }

    public function test_a_loop_with_no_active_strategy_cannot_take_an_action(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create();

        $this->expectException(StrategyTransitionException::class);

        app(CreateAction::class)->handle($loop, $this->clockAction());
    }

    public function test_archiving_keeps_every_occurrence_and_outcome(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveStrategy($user);
        $action = app(CreateAction::class)->handle($loop, $this->clockAction());

        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDay(),
        ]);
        ActionLog::factory()->create([
            'action_id' => $action->id,
            'occurrence_id' => $occurrence->id,
            'user_id' => $user->id,
            'outcome' => ActionLog::OUTCOME_FAILED,
            'reason' => 'Served the second plate before I sat down',
        ]);

        $archived = app(ArchiveAction::class)->handle($action);

        $this->assertSame(Action::STATUS_ARCHIVED, $archived->status);
        $this->assertSame(1, Occurrence::where('action_id', $action->id)->count());
        $this->assertSame(1, ActionLog::where('action_id', $action->id)->count());
        $this->assertSame(
            'Served the second plate before I sat down',
            ActionLog::where('action_id', $action->id)->firstOrFail()->reason,
        );
    }

    public function test_an_archived_action_stops_materialising(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = $this->loopWithActiveStrategy($user);
        $action = app(CreateAction::class)->handle($loop, $this->clockAction());
        $action->update(['series_started_at' => now()->subDays(3)]);

        app(ArchiveAction::class)->handle($action);

        $this->assertSame(0, app(MaterialiseOccurrences::class)->forUser($user));
    }
}
