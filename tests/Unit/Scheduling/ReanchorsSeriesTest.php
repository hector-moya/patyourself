<?php

namespace Tests\Unit\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\ReanchorsSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReanchorsSeriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_purges_unlogged_future_occurrences_and_keeps_logged_ones(): void
    {
        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            // schedule_kind lives in metadata, not a column — see ActionFactory.
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'status' => Action::STATUS_ACTIVE,
            'series_started_at' => now()->subDays(3),
        ]);

        $logged = $action->occurrences()->create(['scheduled_for' => now()->subDay()]);
        $logged->log()->create([
            'user_id' => $user->id,
            'action_id' => $action->id,
            'outcome' => 'completed',
            'logged_at' => now()->subDay(),
        ]);
        $future = $action->occurrences()->create(['scheduled_for' => now()->addDays(2)]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Europe/London', 'Europe/London');

        $this->assertDatabaseHas('occurrences', ['id' => $logged->id]);
        $this->assertDatabaseMissing('occurrences', ['id' => $future->id]);
    }

    public function test_it_leaves_anchored_actions_alone(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'anchored'],
            'recurrence' => null,
            'series_started_at' => null,
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Europe/London', 'Europe/London');

        $this->assertNull($action->refresh()->series_started_at);
    }

    /**
     * A stale anchor's authored local time is not persisted anywhere — only
     * the absolute instant is. Re-arming it must recover that local
     * time-of-day by reading the old instant back through the zone it was
     * authored in, then placing it in the destination zone. Reading it back
     * through the destination zone instead relabels the same instant without
     * moving it: a 07:00 Brisbane habit would land at 21:00 London, not the
     * 07:00 London the user actually wants.
     */
    public function test_it_recovers_the_authored_local_time_across_a_timezone_change(): void
    {
        Carbon::setTestNow('2026-01-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            // 07:00 Australia/Brisbane (no DST, UTC+10) on 2026-01-20, stored
            // as the UTC instant it actually is — same as Schedule::firstOccurrence()
            // always produces.
            'recurrence' => null,
            'series_started_at' => Carbon::parse('2026-01-20 07:00:00', 'Australia/Brisbane')->utc(),
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Australia/Brisbane', 'Europe/London');

        $next = $action->refresh()->series_started_at;

        // London is on GMT (UTC+0, no DST) in January, so the buggy reading —
        // through the destination zone — would have landed this at 21:00, the
        // same absolute instant merely relabelled.
        $this->assertSame('07:00', $next->setTimezone('Europe/London')->format('H:i'));
        $this->assertTrue($next->isFuture());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
