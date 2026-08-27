<?php

namespace Tests\Feature;

use App\Models\Action;
use App\Models\ActionLog;
use App\Models\Intention;
use App\Models\Occurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The in-app catch-up list and the occasion-keyed logging endpoint behind it.
 *
 * The load-bearing behaviour: logging Tuesday's occasion on Friday must record
 * Tuesday and leave the action's next-due pointer alone. The existing
 * action-keyed endpoint cannot do that, which is why this one exists.
 */
class CatchUpScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-26 21:00:00');
    }

    private function action(User $user, string $loopStatus = Intention::STATUS_ACTIVE): Action
    {
        // Anchored in the future, so nothing materialises unless a test wants it.
        $anchor = now()->addWeek()->setTime(19, 0);

        return Action::factory()
            ->for(Intention::factory()->for($user)->state([
                'status' => $loopStatus,
                'title' => 'Eating to 80%',
            ]))
            ->create([
                'title' => 'Dinner',
                'recurrence' => 'daily',
                'series_started_at' => $anchor,
                'status' => Action::STATUS_ACTIVE,
            ]);
    }

    public function test_guests_are_redirected(): void
    {
        $this->get('/catch-up')->assertRedirect('/login');
    }

    public function test_it_lists_unlogged_past_occasions_newest_first(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $older = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(3)]);
        $newer = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDay()]);

        $this->actingAs($user)
            ->get('/catch-up')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('catch-up')
                ->has('occurrences', 2)
                ->where('occurrences.0.id', $newer->id)
                ->where('occurrences.1.id', $older->id)
                ->where('occurrences.0.loop_title', 'Eating to 80%')
                ->where('occurrences.0.action_title', 'Dinner')
                ->where('showing_all', false)
            );
    }

    public function test_it_materialises_before_reading_so_a_never_logged_action_appears(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $anchor = now()->subDays(3)->setTime(19, 0);

        Action::factory()
            ->for(Intention::factory()->for($user))
            ->create([
                'recurrence' => 'daily',
                'series_started_at' => $anchor,
                'status' => Action::STATUS_ACTIVE,
            ]);

        $this->assertSame(0, Occurrence::count());

        $this->actingAs($user)
            ->get('/catch-up')
            ->assertInertia(fn (Assert $page) => $page->has('occurrences', 4));
    }

    public function test_it_omits_logged_future_paused_and_foreign_occasions(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $logged = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(2)]);
        ActionLog::factory()->create([
            'action_id' => $action->id,
            'occurrence_id' => $logged->id,
            'user_id' => $user->id,
        ]);

        Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->addDay()]);

        $paused = $this->action($user, Intention::STATUS_PAUSED);
        Occurrence::factory()->create(['action_id' => $paused->id, 'scheduled_for' => now()->subDay()]);

        $theirs = $this->action(User::factory()->create(['timezone' => 'UTC']));
        Occurrence::factory()->create(['action_id' => $theirs->id, 'scheduled_for' => now()->subDay()]);

        $this->actingAs($user)
            ->get('/catch-up')
            ->assertInertia(fn (Assert $page) => $page->has('occurrences', 0));
    }

    public function test_it_shows_the_recent_window_by_default_and_the_whole_backlog_on_request(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);

        $recent = Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(3)]);
        Occurrence::factory()->create(['action_id' => $action->id, 'scheduled_for' => now()->subDays(40)]);

        $this->actingAs($user)
            ->get('/catch-up')
            ->assertInertia(fn (Assert $page) => $page
                ->has('occurrences', 1)
                ->where('occurrences.0.id', $recent->id)
            );

        // Nothing expires: the whole backlog stays reachable.
        $this->actingAs($user)
            ->get('/catch-up?since=all')
            ->assertInertia(fn (Assert $page) => $page
                ->has('occurrences', 2)
                ->where('showing_all', true)
            );
    }

    public function test_logging_an_occasion_records_it_and_leaves_the_next_due_pointer_alone(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->action($user);
        $seriesStartedAt = $action->series_started_at;
        $occurrence = Occurrence::factory()->create([
            'action_id' => $action->id,
            'scheduled_for' => now()->subDays(3)->setTime(19, 0),
        ]);

        $this->actingAs($user)
            ->from('/catch-up')
            ->post("/occurrences/{$occurrence->id}/logs", [
                'outcome' => ActionLog::OUTCOME_FAILED,
                'reason' => 'Second plate before I noticed',
            ])
            ->assertRedirect('/catch-up');

        $log = ActionLog::firstOrFail();

        $this->assertSame($occurrence->id, $log->occurrence_id);
        $this->assertSame('Second plate before I noticed', $log->reason);
        $this->assertTrue($action->fresh()->series_started_at->equalTo($seriesStartedAt));
    }

    public function test_a_failure_without_a_reason_is_rejected(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $this->action($user)->id,
            'scheduled_for' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->from('/catch-up')
            ->post("/occurrences/{$occurrence->id}/logs", ['outcome' => ActionLog::OUTCOME_FAILED])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, ActionLog::count());
    }

    public function test_an_occasion_cannot_be_logged_twice(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $occurrence = Occurrence::factory()->create([
            'action_id' => $this->action($user)->id,
            'scheduled_for' => now()->subDay(),
        ]);

        $payload = ['outcome' => ActionLog::OUTCOME_COMPLETED];

        $this->actingAs($user)->from('/catch-up')
            ->post("/occurrences/{$occurrence->id}/logs", $payload);
        $this->actingAs($user)->from('/catch-up')
            ->post("/occurrences/{$occurrence->id}/logs", $payload)
            ->assertSessionHasErrors('outcome');

        $this->assertSame(1, ActionLog::count());
    }

    public function test_another_users_occasion_cannot_be_logged(): void
    {
        $occurrence = Occurrence::factory()->create([
            'action_id' => $this->action(User::factory()->create(['timezone' => 'UTC']))->id,
            'scheduled_for' => now()->subDay(),
        ]);

        $this->actingAs(User::factory()->create(['timezone' => 'UTC']))
            ->post("/occurrences/{$occurrence->id}/logs", ['outcome' => ActionLog::OUTCOME_COMPLETED])
            ->assertForbidden();

        $this->assertSame(0, ActionLog::count());
    }
}
