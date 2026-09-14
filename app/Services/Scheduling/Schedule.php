<?php

namespace App\Services\Scheduling;

use App\Actions\RescheduleAction;
use Carbon\CarbonImmutable;

/**
 * Pure schedule math for action triggers. Turns an authored local time-of-day +
 * recurrence into the first UTC fire time, and rolls a recurring action forward
 * to its next fire time. Stored datetimes are UTC; the user's IANA timezone
 * localises them. `MaterialiseOccurrences` walks a grid with advance(); the
 * trigger engine only fires the occasions that walk already wrote, and never
 * reaches this class.
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
     * one-off (no recurrence). Weekday and monthly maths are evaluated in the
     * user's timezone.
     *
     * `$anchor` is where the action's current cadence began, and **only the
     * monthly arm reads it**. That asymmetry is deliberate. Daily, weekly and
     * fortnightly step by exact durations, so walking from the anchor and
     * stepping from the last slot agree exactly; months are not a duration, so
     * for monthly they do not — see nextMonthly(). Weekdays must stay pairwise
     * because "the anchor plus n weekdays" is business-day counting rather
     * than calendar counting.
     *
     * It is non-nullable and undefaulted so that a caller cannot quietly omit
     * it and get a monthly series that drifts off its own day of the month.
     */
    public function advance(CarbonImmutable $current, ?Recurrence $recurrence, string $timezone, CarbonImmutable $anchor): ?CarbonImmutable
    {
        $local = $current->setTimezone($timezone);

        return match ($recurrence) {
            Recurrence::Daily => $local->addDay()->utc(),
            Recurrence::Weekdays => $this->skipWeekend($local->addDay())->utc(),
            Recurrence::Weekly => $local->addWeek()->utc(),
            Recurrence::Fortnightly => $local->addWeeks(2)->utc(),
            Recurrence::Monthly => $this->nextMonthly($local, $anchor->setTimezone($timezone)),
            null => null,
        };
    }

    /**
     * The monthly slot after `$local`, keeping the anchor's day of the month.
     *
     * Computed from the anchor rather than from the previous slot, because a
     * month is not a duration. Stepping pairwise with addMonthNoOverflow()
     * takes an action anchored on the 31st to Feb 28 and then leaves it on the
     * 28th forever — one short February and the action has silently changed
     * what it means. Anchored arithmetic clamps in a short month and returns
     * to the 31st in the next long one.
     *
     * The step count is derived from the calendar fields rather than from
     * Carbon's diffInMonths(), which counts *complete* months: Jan 31 to Feb 28
     * is zero complete months while being unambiguously one step of this grid.
     */
    private function nextMonthly(CarbonImmutable $local, CarbonImmutable $anchorLocal): CarbonImmutable
    {
        $elapsed = ($local->year - $anchorLocal->year) * 12
            + ($local->month - $anchorLocal->month);

        return $anchorLocal->addMonthsNoOverflow($elapsed + 1)->utc();
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
            // `$from` is the series anchor at every call site — onOrAfter()
            // passes the candidate anchor, ReanchorsSeries the action's, and
            // StartExperiment the prior one — so it is what monthly needs.
            // Threading a separate parameter through here would add a way to
            // get that wrong without adding a case it gets right.
            $next = $this->advance($next, $recurrence, $timezone, $from);
        } while ($next !== null && $next->lessThanOrEqualTo($now));

        return $next;
    }

    /**
     * The next slot that is safe to **persist** as a series anchor.
     *
     * Identical to nextAfter() for every cadence but monthly, and the
     * distinction exists only because the anchor is doing two jobs at once: it
     * says when the series starts, and for monthly it also carries the day of
     * the month the whole grid is computed from.
     *
     * nextAfter() is right to return February's clamped 28th — it genuinely is
     * the next occasion. But a caller that stores it makes the clamp permanent:
     * every later month becomes the 28th, which is the corruption
     * {@see self::advance()} exists to prevent, arriving by a different route.
     *
     * So a monthly anchor walks on to the next month that can hold the day the
     * owner chose, giving up that one occasion rather than the cadence. It
     * terminates after at most one extra step: no two consecutive months are
     * both too short for the same day.
     */
    public function nextAnchorAfter(CarbonImmutable $anchor, CarbonImmutable $now, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        $next = $this->nextAfter($anchor, $now, $recurrence, $timezone);

        if ($next === null || $recurrence !== Recurrence::Monthly) {
            return $next;
        }

        $day = $anchor->setTimezone($timezone)->day;

        while ($next !== null && $next->setTimezone($timezone)->day !== $day) {
            $next = $this->advance($next, $recurrence, $timezone, $anchor);
        }

        return $next;
    }

    /**
     * The candidate itself when it is already ahead of `now`, otherwise the
     * first slot of its own grid that is.
     *
     * The sibling of nextAfter(), not a wrapper around it: nextAfter() advances
     * at least once unconditionally, which is right for "the slot after this
     * one fired" and wrong here, where a candidate already in the future must
     * come back untouched. Calling it directly would silently push every future
     * start date one period later.
     *
     * A null recurrence returns the candidate unchanged. A one-off has no grid
     * to walk along — its date is the whole schedule — and refusing to store a
     * past one is {@see RescheduleAction}'s decision, not this
     * function's.
     */
    public function onOrAfter(CarbonImmutable $candidate, CarbonImmutable $now, ?Recurrence $recurrence, string $timezone): CarbonImmutable
    {
        if ($recurrence === null || $candidate->greaterThan($now)) {
            return $candidate;
        }

        // nextAfter() returns null only for a null recurrence, which the guard
        // above has already returned on.
        return $this->nextAfter($candidate, $now, $recurrence, $timezone) ?? $candidate;
    }

    /**
     * The series anchor a user's edit describes, in UTC. Null when there is no
     * clock time — an anchored action the scheduler never fires.
     *
     * Without a date the anchor is *derived*: this delegates to
     * firstOccurrence(), which resolves the next occurrence at or after now
     * with that local time. That is the path daily and weekdays take (a date
     * says nothing about "every day"), and the path the JSON API and the MCP
     * connector take, neither of which sends one.
     *
     * With a date the anchor is *chosen*, and a date names a day rather than an
     * instant: "Wednesday, weekly" means every Wednesday. So a date that has
     * passed is snapped forward onto its own grid rather than refused — the
     * user named a weekday, and next Wednesday is what they named. Accepting it
     * where it lies would be worse than refusing it: MaterialiseOccurrences
     * walks from the anchor to the end of the local day, so a back-dated anchor
     * does not record history, it mints occasions nobody was ever asked about.
     *
     * The weekend bump firstOccurrence() applies is kept here. No form sends a
     * date alongside `weekdays`, but advance() never produces a weekend slot,
     * so an anchor on one would put the whole grid half a step off its own rule.
     */
    public function anchorAt(CarbonImmutable $now, ?string $localDate, ?string $localTime, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
    {
        if ($localTime === null) {
            return null;
        }

        if ($localDate === null) {
            return $this->firstOccurrence($now, $localTime, $recurrence, $timezone);
        }

        [$hour, $minute] = array_map('intval', explode(':', $localTime));
        [$year, $month, $day] = array_map('intval', explode('-', $localDate));

        // Built by converting `now` into the user's zone and moving it, rather
        // than by parsing the date string, so the local-time arithmetic is the
        // same shape firstOccurrence() uses.
        $candidate = $now->setTimezone($timezone)
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, 0);

        if ($recurrence === Recurrence::Weekdays) {
            $candidate = $this->skipWeekend($candidate);
        }

        return $this->onOrAfter($candidate, $now, $recurrence, $timezone)->utc();
    }

    private function skipWeekend(CarbonImmutable $date): CarbonImmutable
    {
        while ($date->isWeekend()) {
            $date = $date->addDay();
        }

        return $date;
    }
}
