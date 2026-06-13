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
}
