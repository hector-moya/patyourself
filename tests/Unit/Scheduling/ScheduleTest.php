<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\Recurrence;
use App\Services\Scheduling\Schedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    private function at(string $utc): CarbonImmutable
    {
        return CarbonImmutable::parse($utc, 'UTC');
    }

    public function test_first_daily_occurrence_today_when_time_is_ahead(): void
    {
        $next = (new Schedule)->firstOccurrence($this->at('2026-06-13 06:00:00'), '07:00', Recurrence::Daily, 'UTC');

        $this->assertSame('2026-06-13 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_first_daily_occurrence_rolls_to_tomorrow_when_time_passed(): void
    {
        $next = (new Schedule)->firstOccurrence($this->at('2026-06-13 08:00:00'), '07:00', Recurrence::Daily, 'UTC');

        $this->assertSame('2026-06-14 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_first_weekday_occurrence_skips_the_weekend(): void
    {
        $friday = $this->at('2026-06-12 08:00:00');
        $this->assertTrue($friday->isFriday()); // self-documenting anchor

        $next = (new Schedule)->firstOccurrence($friday, '07:00', Recurrence::Weekdays, 'UTC');

        $this->assertSame('2026-06-15 07:00:00', $next->utc()->format('Y-m-d H:i:s')); // Monday
    }

    public function test_first_occurrence_converts_local_time_to_utc(): void
    {
        $next = (new Schedule)->firstOccurrence($this->at('2026-06-13 00:00:00'), '07:00', Recurrence::Daily, 'America/New_York');

        // 07:00 EDT (UTC-4) the next NY morning == 11:00 UTC.
        $this->assertSame('2026-06-13 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_anchored_action_has_no_occurrence(): void
    {
        $this->assertNull((new Schedule)->firstOccurrence($this->at('2026-06-13 06:00:00'), null, null, 'UTC'));
    }

    public function test_advance_rolls_each_recurrence_forward(): void
    {
        $schedule = new Schedule;
        $current = $this->at('2026-06-12 11:00:00'); // Fri 07:00 EDT

        $this->assertSame('2026-06-13 11:00:00', $schedule->advance($current, Recurrence::Daily, 'America/New_York')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 11:00:00', $schedule->advance($current, Recurrence::Weekdays, 'America/New_York')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-19 11:00:00', $schedule->advance($current, Recurrence::Weekly, 'America/New_York')->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($schedule->advance($current, null, 'America/New_York'));
    }

    public function test_next_after_takes_one_step_for_a_fresh_base(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-06-13 07:00:00');     // the occurrence that just fired
        $now = $this->at('2026-06-13 07:00:30');      // a moment later

        $next = $schedule->nextAfter($base, $now, Recurrence::Daily, 'UTC');

        $this->assertSame('2026-06-14 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_next_after_fast_forwards_past_stale_daily_slots(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-06-10 07:00:00');     // 3 days stale
        $now = $this->at('2026-06-13 09:00:00');

        $next = $schedule->nextAfter($base, $now, Recurrence::Daily, 'UTC');

        // First daily slot strictly after now (06-13 07:00 is before 09:00).
        $this->assertSame('2026-06-14 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_next_after_weekly_preserves_the_weekday(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-05-29 07:00:00');     // a Friday
        $this->assertTrue($base->isFriday());
        $now = $this->at('2026-06-13 09:00:00');

        $next = $schedule->nextAfter($base, $now, Recurrence::Weekly, 'UTC');

        $this->assertSame('2026-06-19 07:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($next->isFriday());          // still a Friday
    }

    public function test_next_after_weekdays_skips_the_weekend(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-06-12 07:00:00');     // a Friday
        $now = $this->at('2026-06-13 09:00:00');      // Saturday

        $next = $schedule->nextAfter($base, $now, Recurrence::Weekdays, 'UTC');

        $this->assertSame('2026-06-15 07:00:00', $next->utc()->format('Y-m-d H:i:s')); // Monday
    }

    public function test_next_after_returns_null_for_a_one_off(): void
    {
        $next = (new Schedule)->nextAfter($this->at('2026-06-13 07:00:00'), $this->at('2026-06-13 08:00:00'), null, 'UTC');

        $this->assertNull($next);
    }

    public function test_advance_holds_wall_clock_across_spring_forward(): void
    {
        // 07:00 in New York on Sat 2026-03-07 (EST, UTC-5) == 12:00 UTC.
        $current = $this->at('2026-03-07 12:00:00');

        $next = (new Schedule)->advance($current, Recurrence::Daily, 'America/New_York');

        // Sun 2026-03-08 is EDT (UTC-4): 07:00 local == 11:00 UTC (UTC shifts, local holds).
        $this->assertSame('2026-03-08 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_advance_holds_wall_clock_across_fall_back(): void
    {
        // 07:00 in New York on Sat 2026-10-31 (EDT, UTC-4) == 11:00 UTC.
        $current = $this->at('2026-10-31 11:00:00');

        $next = (new Schedule)->advance($current, Recurrence::Daily, 'America/New_York');

        // Sun 2026-11-01 is EST (UTC-5): 07:00 local == 12:00 UTC.
        $this->assertSame('2026-11-01 12:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_next_after_fast_forwards_across_a_dst_boundary(): void
    {
        $schedule = new Schedule;
        $base = $this->at('2026-03-06 12:00:00');     // Fri 07:00 EST
        $now = $this->at('2026-03-09 09:00:00');      // Mon, after spring-forward

        $next = $schedule->nextAfter($base, $now, Recurrence::Daily, 'America/New_York');

        // Mon 2026-03-09 07:00 EDT == 11:00 UTC; still 07:00 local.
        $this->assertSame('2026-03-09 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_advance_survives_the_spring_forward_gap_hour(): void
    {
        // 02:30 New York on 2026-03-07 (EST) == 07:30 UTC. Advancing a day lands
        // on 2026-03-08, when 02:30 local does not exist (clocks jump 02:00->03:00).
        // We assert it produces a valid instant strictly after the base rather than
        // a brittle exact value, since gap resolution is Carbon-version dependent.
        $base = $this->at('2026-03-07 07:30:00');

        $next = (new Schedule)->advance($base, Recurrence::Daily, 'America/New_York');

        $this->assertNotNull($next);
        $this->assertTrue($next->utc()->greaterThan($base));
    }
}
