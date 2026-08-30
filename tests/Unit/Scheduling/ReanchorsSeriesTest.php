<?php

namespace Tests\Unit\Scheduling;

use App\Models\Action;
use App\Models\Intention;
use App\Models\User;
use App\Services\Scheduling\ReanchorsSeries;
use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
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

    /**
     * The recurring twin of the test above. Once TimezoneController filters
     * to genuinely stale actions, a stale recurring habit — the routine case
     * for an established loop — is the branch that actually gets exercised in
     * production; a one-off is the rare case. nextAfter() must see an anchor
     * already rebased into the destination zone, or it advances from the raw
     * old-zone instant and lands on the destination zone's clock-hour for
     * that instant instead of the authored time-of-day.
     */
    public function test_a_stale_daily_action_recovers_the_authored_local_time_across_a_timezone_change(): void
    {
        Carbon::setTestNow('2026-01-24 12:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'series_started_at' => Carbon::parse('2026-01-20 07:00:00', 'Australia/Brisbane')->utc(),
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Australia/Brisbane', 'Europe/London');

        $next = $action->refresh()->series_started_at;

        // Unrebased, nextAfter() would advance from the raw old-zone instant
        // and land at 21:00 London (the same bug as the one-off case, via the
        // other branch) rather than 07:00.
        $this->assertSame('07:00', $next->setTimezone('Europe/London')->format('H:i'));
        $this->assertTrue($next->isFuture());
    }

    /**
     * The date half of the rebase has to come from the zone the anchor was
     * authored in, not from the instant's own UTC-backed representation: an
     * anchor authored at 07:00 on a Monday in Brisbane is 21:00 the preceding
     * *Sunday* in London. Reading the date off the wrong side would silently
     * shift a weekly habit's weekday across the zone change.
     */
    public function test_a_stale_weekly_action_keeps_its_weekday_across_a_timezone_change(): void
    {
        Carbon::setTestNow('2026-02-20 12:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'weekly',
            // 2026-01-05 is a Monday. 07:00 Monday Brisbane is 21:00 the
            // preceding Sunday in UTC (and, that January, in London too).
            'series_started_at' => Carbon::parse('2026-01-05 07:00:00', 'Australia/Brisbane')->utc(),
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Australia/Brisbane', 'Europe/London');

        $next = $action->refresh()->series_started_at->setTimezone('Europe/London');

        $this->assertTrue($next->isMonday());
        $this->assertSame('07:00', $next->format('H:i'));
        $this->assertTrue($next->isFuture());
    }

    /**
     * When the two zones are the same — RescheduleAction and UpdateIntention's
     * own calls, where the anchor's zone never actually changes — the rebase
     * must reconstruct the exact original instant, not merely land close
     * enough to pass a weekday or clock-time assertion. Compares the
     * service's output directly against calling Schedule::nextAfter() on the
     * un-rebased anchor, using an off-the-hour time so any lost precision
     * would show up as a real difference rather than a coincidental match.
     */
    public function test_the_rebase_is_an_exact_no_op_when_the_zones_match(): void
    {
        Carbon::setTestNow('2026-08-26 09:00:00');

        $user = User::factory()->create(['timezone' => 'Australia/Brisbane']);
        $loop = Intention::factory()->for($user)->create();
        $originalAnchor = Carbon::parse('2026-08-20 07:13:47', 'Australia/Brisbane')->utc();
        $action = Action::factory()->for($loop, 'intention')->create([
            'metadata' => ['schedule_kind' => 'clock'],
            'recurrence' => 'daily',
            'series_started_at' => $originalAnchor,
            'status' => Action::STATUS_ACTIVE,
        ]);

        app(ReanchorsSeries::class)->forActions(collect([$action]), 'Australia/Brisbane', 'Australia/Brisbane');

        $expected = (new Schedule)->nextAfter(
            $originalAnchor->toImmutable(),
            CarbonImmutable::now(),
            Recurrence::Daily,
            'Australia/Brisbane',
        );

        $this->assertTrue($action->refresh()->series_started_at->equalTo($expected));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
