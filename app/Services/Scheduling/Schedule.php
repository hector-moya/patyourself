<?php

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Pure schedule math for action triggers. Turns an authored local time-of-day +
 * recurrence into the first UTC fire time, and rolls a recurring action forward
 * to its next fire time. Stored datetimes are UTC; the user's IANA timezone
 * localises them. SP2's trigger engine reuses advance() after firing.
 */
final readonly class Schedule
{
    /**
     * The first fire time at or after `now`, in UTC. Null when there is no clock
     * time (an anchored action the scheduler never fires).
     */
    public function firstOccurrence(CarbonImmutable $now, ?string $localTime, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        if ($localTime === null) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $localTime));

        $local = $now->setTimezone($timezone);
        $candidate = $local->setTime($hour, $minute, 0);

        if ($candidate->lessThanOrEqualTo($local)) {
            $candidate = $candidate->addDay();
        }

        if ($recurrence === Recurrence::Weekdays) {
            $candidate = $this->skipWeekend($candidate);
        }

        return $candidate->utc();
    }

    /**
     * The next fire time after a recurring action fires, in UTC. Null for a
     * one-off (no recurrence). Weekday math is evaluated in the user's timezone.
     */
    public function advance(CarbonImmutable $current, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        $local = $current->setTimezone($timezone);

        return match ($recurrence) {
            Recurrence::Daily => $local->addDay()->utc(),
            Recurrence::Weekdays => $this->skipWeekend($local->addDay())->utc(),
            Recurrence::Weekly => $local->addWeek()->utc(),
            null => null,
        };
    }

    /**
     * The next fire time strictly after `now`, in UTC — fast-forwarding past any
     * occurrences missed while the app was down. Repeatedly applies advance()
     * (which preserves wall-clock time in the user's timezone, so it is
     * DST-correct and keeps weekly's weekday). Null for a one-off, which is never
     * re-armed.
     */
    public function nextAfter(CarbonImmutable $from, CarbonImmutable $now, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        if ($recurrence === null) {
            return null;
        }

        $next = $from;

        do {
            $next = $this->advance($next, $recurrence, $timezone);
        } while ($next !== null && $next->lessThanOrEqualTo($now));

        return $next;
    }

    private function skipWeekend(CarbonImmutable $date): CarbonImmutable
    {
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }
}
