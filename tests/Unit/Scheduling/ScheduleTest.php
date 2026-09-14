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

        $this->assertSame('2026-06-13 11:00:00', $schedule->advance($current, Recurrence::Daily, 'America/New_York', $current)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 11:00:00', $schedule->advance($current, Recurrence::Weekdays, 'America/New_York', $current)->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-19 11:00:00', $schedule->advance($current, Recurrence::Weekly, 'America/New_York', $current)->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($schedule->advance($current, null, 'America/New_York', $current));
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

        $next = (new Schedule)->advance($current, Recurrence::Daily, 'America/New_York', $current);

        // Sun 2026-03-08 is EDT (UTC-4): 07:00 local == 11:00 UTC (UTC shifts, local holds).
        $this->assertSame('2026-03-08 11:00:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:00', $next->setTimezone('America/New_York')->format('H:i'));
    }

    public function test_advance_holds_wall_clock_across_fall_back(): void
    {
        // 07:00 in New York on Sat 2026-10-31 (EDT, UTC-4) == 11:00 UTC.
        $current = $this->at('2026-10-31 11:00:00');

        $next = (new Schedule)->advance($current, Recurrence::Daily, 'America/New_York', $current);

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

        $next = (new Schedule)->advance($base, Recurrence::Daily, 'America/New_York', $base);

        $this->assertNotNull($next);
        $this->assertTrue($next->utc()->greaterThan($base));
    }

    /**
     * 2026-09-14 is a Monday and 2026-09-16 is the Wednesday after it. The
     * weekday assertions below are self-documenting anchors, in the style the
     * weekend-skipping test above already uses.
     */
    public function test_on_or_after_returns_a_candidate_that_is_already_ahead(): void
    {
        $now = $this->at('2026-09-14 12:00:00');
        $candidate = $this->at('2026-09-23 07:30:00');

        $result = (new Schedule)->onOrAfter($candidate, $now, Recurrence::Weekly, 'UTC');

        $this->assertSame('2026-09-23 07:30:00', $result->utc()->format('Y-m-d H:i:s'));
    }

    public function test_on_or_after_walks_a_past_weekly_candidate_to_its_next_weekday(): void
    {
        $now = $this->at('2026-09-14 12:00:00');
        $wednesday = $this->at('2026-09-09 07:30:00');
        $this->assertTrue($wednesday->isWednesday());

        $result = (new Schedule)->onOrAfter($wednesday, $now, Recurrence::Weekly, 'UTC');

        $this->assertSame('2026-09-16 07:30:00', $result->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($result->isWednesday());
    }

    public function test_on_or_after_walks_a_past_daily_candidate_to_the_next_day(): void
    {
        $now = $this->at('2026-09-14 12:00:00');

        $result = (new Schedule)->onOrAfter($this->at('2026-09-10 07:00:00'), $now, Recurrence::Daily, 'UTC');

        $this->assertSame('2026-09-15 07:00:00', $result->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * A one-off has no grid to walk along — its date is the whole schedule.
     * Returned unchanged even when it has passed; refusing to *store* a past
     * one-off is RescheduleAction's job, not this function's.
     */
    public function test_on_or_after_leaves_a_past_one_off_where_it_is(): void
    {
        $now = $this->at('2026-09-14 12:00:00');
        $past = $this->at('2026-09-10 09:00:00');

        $result = (new Schedule)->onOrAfter($past, $now, null, 'UTC');

        $this->assertSame('2026-09-10 09:00:00', $result->utc()->format('Y-m-d H:i:s'));
    }

    public function test_anchor_at_returns_null_without_a_time(): void
    {
        $anchor = (new Schedule)->anchorAt($this->at('2026-09-14 12:00:00'), '2026-09-23', null, Recurrence::Weekly, 'UTC');

        $this->assertNull($anchor);
    }

    /**
     * The delegating branch, asserted against firstOccurrence() itself rather
     * than against a literal, so the two cannot drift apart. Without a date,
     * daily, weekdays, the JSON API and the MCP connector all take exactly the
     * path they take today.
     */
    public function test_anchor_at_without_a_date_matches_first_occurrence(): void
    {
        $schedule = new Schedule;
        $now = $this->at('2026-09-14 12:00:00');

        foreach ([Recurrence::Daily, Recurrence::Weekdays, Recurrence::Weekly, null] as $recurrence) {
            $this->assertTrue(
                $schedule->anchorAt($now, null, '07:00', $recurrence, 'Europe/London')
                    ->equalTo($schedule->firstOccurrence($now, '07:00', $recurrence, 'Europe/London')),
                'anchorAt must delegate to firstOccurrence when no date is given.',
            );
        }
    }

    public function test_anchor_at_takes_a_future_date_and_time_as_given(): void
    {
        $anchor = (new Schedule)->anchorAt($this->at('2026-09-14 12:00:00'), '2026-09-23', '07:30', Recurrence::Weekly, 'UTC');

        $this->assertSame('2026-09-23 07:30:00', $anchor->utc()->format('Y-m-d H:i:s'));
    }

    public function test_anchor_at_snaps_a_past_weekly_date_to_the_next_same_weekday(): void
    {
        $anchor = (new Schedule)->anchorAt($this->at('2026-09-14 12:00:00'), '2026-09-09', '07:30', Recurrence::Weekly, 'UTC');

        $this->assertSame('2026-09-16 07:30:00', $anchor->utc()->format('Y-m-d H:i:s'));
    }

    public function test_anchor_at_moves_a_weekend_date_off_the_weekend_for_weekdays(): void
    {
        $saturday = $this->at('2026-09-19 00:00:00');
        $this->assertTrue($saturday->isSaturday());

        $anchor = (new Schedule)->anchorAt($this->at('2026-09-14 12:00:00'), '2026-09-19', '07:00', Recurrence::Weekdays, 'UTC');

        $this->assertTrue($anchor->isMonday());
        $this->assertSame('2026-09-21 07:00:00', $anchor->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * For daily and weekdays a **past** date is inert: snapping it forward, one
     * period at a time, arrives exactly where the derived path would have put
     * it. That property is why a past date reaching those recurrences by any
     * route needs no validation rule to make it safe.
     *
     * The property is one-directional, and the test below says why.
     */
    public function test_anchor_at_with_a_past_date_converges_on_first_occurrence_for_daily(): void
    {
        $schedule = new Schedule;
        $now = $this->at('2026-09-14 12:00:00');

        $this->assertTrue(
            $schedule->anchorAt($now, '2026-08-01', '07:00', Recurrence::Daily, 'UTC')
                ->equalTo($schedule->firstOccurrence($now, '07:00', Recurrence::Daily, 'UTC')),
        );
    }

    /**
     * A *future* date is not inert, because `onOrAfter()` returns a candidate
     * that is already ahead untouched — by design, so a chosen start date is
     * never pushed a period later.
     *
     * The editor cannot produce this: the date input unmounts for `daily` and
     * `weekdays`, so no date is posted. A crafted request can, and what it gets
     * is coherent rather than surprising — the series starts in January and
     * materialises nothing until then. Recorded here because the convergence
     * above is the stated reason for adding no validation rule, so the limit of
     * that convergence has to be on the record too.
     */
    public function test_anchor_at_takes_a_future_date_on_daily_as_given(): void
    {
        $schedule = new Schedule;
        $now = $this->at('2026-09-14 12:00:00');

        $anchor = $schedule->anchorAt($now, '2027-01-04', '07:00', Recurrence::Daily, 'UTC');

        $this->assertSame('2027-01-04 07:00:00', $anchor->utc()->format('Y-m-d H:i:s'));
        $this->assertFalse(
            $anchor->equalTo($schedule->firstOccurrence($now, '07:00', Recurrence::Daily, 'UTC')),
            'A future date is deliberately not inert: it anchors where it says.',
        );
    }

    /**
     * The date is read in the user's zone, not the server's. 07:30 in London
     * on a British Summer Time date is 06:30 UTC.
     */
    public function test_anchor_at_reads_the_date_in_the_users_zone(): void
    {
        $anchor = (new Schedule)->anchorAt($this->at('2026-09-14 12:00:00'), '2026-09-23', '07:30', Recurrence::Weekly, 'Europe/London');

        $this->assertSame('2026-09-23 06:30:00', $anchor->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:30', $anchor->setTimezone('Europe/London')->format('H:i'));
    }

    /**
     * The defect anchor-relative monthly exists to prevent. Stepping from the
     * previous slot, an action anchored on the 31st reaches Feb 28 and then
     * never leaves the 28th: the action has silently changed what it means.
     *
     * Killing mutation: compute from `$current` instead of `$anchor`. March
     * then lands on the 28th.
     */
    public function test_monthly_recovers_the_day_of_the_month_after_a_short_one(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-01-31 09:00:00');

        $february = $schedule->advance($anchor, Recurrence::Monthly, 'UTC', $anchor);
        $this->assertSame('2026-02-28 09:00:00', $february->utc()->format('Y-m-d H:i:s'));

        $march = $schedule->advance($february, Recurrence::Monthly, 'UTC', $anchor);
        $this->assertSame('2026-03-31 09:00:00', $march->utc()->format('Y-m-d H:i:s'));

        $april = $schedule->advance($march, Recurrence::Monthly, 'UTC', $anchor);
        $this->assertSame('2026-04-30 09:00:00', $april->utc()->format('Y-m-d H:i:s'));
    }

    public function test_monthly_clamps_a_thirtieth_into_february_and_recovers(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-01-30 09:00:00');

        $february = $schedule->advance($anchor, Recurrence::Monthly, 'UTC', $anchor);
        $this->assertSame('2026-02-28 09:00:00', $february->utc()->format('Y-m-d H:i:s'));

        $march = $schedule->advance($february, Recurrence::Monthly, 'UTC', $anchor);
        $this->assertSame('2026-03-30 09:00:00', $march->utc()->format('Y-m-d H:i:s'));
    }

    public function test_monthly_is_unremarkable_for_a_day_every_month_has(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-01-15 09:00:00');

        $this->assertSame(
            '2026-02-15 09:00:00',
            $schedule->advance($anchor, Recurrence::Monthly, 'UTC', $anchor)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /** February gains a day in a leap year, and the clamp has to notice. */
    public function test_monthly_clamps_to_the_twenty_ninth_in_a_leap_year(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2028-01-31 09:00:00');
        $this->assertTrue($anchor->addMonthsNoOverflow(1)->isLeapYear());

        $this->assertSame(
            '2028-02-29 09:00:00',
            $schedule->advance($anchor, Recurrence::Monthly, 'UTC', $anchor)->utc()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * The day of the month is a fact about the owner's calendar, not the
     * server's.
     *
     * The anchor is deliberately one whose two calendars disagree about both
     * the day *and* the month: 2026-02-28 13:30 UTC is 2026-03-01 00:30 in
     * Sydney — the 1st of March there, the 28th of February here. An anchor
     * whose two dates happen to coincide would pass whether the maths read the
     * owner's zone or the server's, which is no assertion at all.
     *
     * Killing mutation: drop the `setTimezone($timezone)` round trip from
     * advance(). The step is then computed in UTC and lands on 2026-03-29
     * 00:30 Sydney instead of 2026-04-01 00:30.
     */
    public function test_monthly_reads_the_day_of_the_month_in_the_owners_zone(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-02-28 13:30:00');
        $this->assertSame('1', $anchor->setTimezone('Australia/Sydney')->format('j'));
        $this->assertSame('28', $anchor->format('j')); // the same instant, in UTC

        $next = $schedule->advance($anchor, Recurrence::Monthly, 'Australia/Sydney', $anchor);

        $this->assertSame(
            '2026-04-01 00:30',
            $next->setTimezone('Australia/Sydney')->format('Y-m-d H:i'),
        );
    }

    public function test_fortnightly_steps_two_weeks_and_keeps_its_weekday(): void
    {
        $schedule = new Schedule;
        $tuesday = $this->at('2026-09-15 07:30:00');
        $this->assertTrue($tuesday->isTuesday());

        $next = $schedule->advance($tuesday, Recurrence::Fortnightly, 'UTC', $tuesday);

        $this->assertSame('2026-09-29 07:30:00', $next->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($next->isTuesday());
    }

    /**
     * Weekdays is deliberately pairwise: "the anchor plus n weekdays" is
     * business-day counting, not calendar counting, and would need a different
     * computation from every other arm. Asserted by handing it an anchor that
     * has nothing to do with the slot and getting the same answer.
     */
    public function test_weekdays_ignores_the_anchor(): void
    {
        $schedule = new Schedule;
        $friday = $this->at('2026-06-12 08:00:00');
        $this->assertTrue($friday->isFriday());

        $fromItsOwnAnchor = $schedule->advance($friday, Recurrence::Weekdays, 'UTC', $friday);
        $fromAnUnrelatedOne = $schedule->advance($friday, Recurrence::Weekdays, 'UTC', $this->at('2019-03-07 04:00:00'));

        $this->assertTrue($fromItsOwnAnchor->equalTo($fromAnUnrelatedOne));
        $this->assertSame('2026-06-15 08:00:00', $fromItsOwnAnchor->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * The defect Task 2's review found. nextAfter() is right to return the
     * clamped slot — it is genuinely the next occasion — but a caller that
     * *persists* it as the anchor makes the clamp permanent, because the grid
     * is computed from the anchor's day of the month.
     *
     * Killing mutation: have nextAnchorAfter() simply return nextAfter()'s
     * answer. The assertion below then reads 2026-02-28.
     */
    public function test_a_monthly_anchor_never_lands_on_a_clamped_slot(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-01-31 09:00:00');

        $next = $schedule->nextAnchorAfter($anchor, $this->at('2026-02-20 12:00:00'), Recurrence::Monthly, 'UTC');

        $this->assertSame('2026-03-31 09:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_monthly_anchor_that_does_not_clamp_is_the_next_occasion(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-01-15 09:00:00');

        $next = $schedule->nextAnchorAfter($anchor, $this->at('2026-01-20 12:00:00'), Recurrence::Monthly, 'UTC');

        $this->assertSame('2026-02-15 09:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * Only monthly has a day of the month to lose, so every other cadence must
     * get exactly what nextAfter() says — asserted against nextAfter() itself
     * so the two cannot drift apart.
     */
    public function test_every_other_cadence_re_anchors_exactly_as_it_always_did(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-01-31 09:00:00');
        $now = $this->at('2026-02-20 12:00:00');

        foreach ([Recurrence::Daily, Recurrence::Weekdays, Recurrence::Weekly, Recurrence::Fortnightly] as $recurrence) {
            $this->assertTrue(
                $schedule->nextAnchorAfter($anchor, $now, $recurrence, 'UTC')
                    ->equalTo($schedule->nextAfter($anchor, $now, $recurrence, 'UTC')),
                "{$recurrence->value} must re-anchor exactly as nextAfter() says.",
            );
        }
    }

    /** A one-off has no next slot, and its callers fall back to firstOccurrence(). */
    public function test_a_one_off_has_no_next_anchor(): void
    {
        $next = (new Schedule)->nextAnchorAfter(
            $this->at('2026-01-31 09:00:00'),
            $this->at('2026-02-20 12:00:00'),
            null,
            'UTC',
        );

        $this->assertNull($next);
    }

    /**
     * The day of the month is read in the owner's zone, as the grid maths is.
     *
     * New York rather than Sydney, and 20:00 rather than 23:30, so that the two
     * calendars disagree in the direction that makes the check observable:
     * 2026-02-01 01:00 UTC is 2026-01-31 20:00 in New York. The owner's day is
     * the 31st, the server's is the 1st — and the 1st is a day February can
     * hold, so a clamp check run in UTC is satisfied by February's clamped slot
     * and stops there.
     *
     * Killing mutation: read `$anchor->day` and `$next->day` in
     * nextAnchorAfter() instead of localising first. The answer becomes
     * 2026-02-28 20:00 New York — the clamped slot this method exists to walk
     * past — rather than 2026-03-31 20:00.
     */
    public function test_a_monthly_anchor_is_clamp_checked_in_the_owners_zone(): void
    {
        $anchor = $this->at('2026-02-01 01:00:00');
        $this->assertSame('31', $anchor->setTimezone('America/New_York')->format('j'));
        $this->assertSame('1', $anchor->format('j')); // the same instant, in UTC

        $next = (new Schedule)->nextAnchorAfter(
            $anchor,
            $this->at('2026-02-20 12:00:00'),
            Recurrence::Monthly,
            'America/New_York',
        );

        $this->assertSame(
            '2026-03-31 20:00',
            $next->setTimezone('America/New_York')->format('Y-m-d H:i'),
        );
    }

    /**
     * The year term in nextMonthly()'s elapsed-months count.
     *
     * The first step (Dec 31 -> Jan 31) is pinned because it is a good year
     * boundary to read, but it does not discriminate this mutation: `$current`
     * equals `$anchor`, so the year term is multiplied by zero either way.
     *
     * The second step is what kills it. `$current` (2027-01-31) is a year
     * after `$anchor` (2026-12-31), so elapsed is normally
     * (2027-2026)*12 + (1-12) = 1, landing on 2027-02-28.
     *
     * Killing mutation: drop the `* 12`. Elapsed becomes
     * (2027-2026) + (1-12) = -10, and the walk lands back in March 2026.
     */
    public function test_monthly_crosses_a_year_boundary(): void
    {
        $schedule = new Schedule;
        $anchor = $this->at('2026-12-31 09:00:00');

        $next = $schedule->advance($anchor, Recurrence::Monthly, 'UTC', $anchor);

        $this->assertSame('2027-01-31 09:00:00', $next->utc()->format('Y-m-d H:i:s'));

        $next = $schedule->advance($next, Recurrence::Monthly, 'UTC', $anchor);

        $this->assertSame('2027-02-28 09:00:00', $next->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * onOrAfter() is right to hand back February's clamped slot: it answers
     * "which occasion does this date fall on", and that genuinely is the one.
     * Pinned so the difference from anchorOnOrAfter() below is a documented
     * distinction rather than an accident of which one a caller reached for.
     */
    public function test_on_or_after_walks_a_past_monthly_candidate_onto_its_clamped_slot(): void
    {
        $result = (new Schedule)->onOrAfter(
            $this->at('2026-01-31 07:30:00'),
            $this->at('2026-02-10 12:00:00'),
            Recurrence::Monthly,
            'UTC',
        );

        $this->assertSame('2026-02-28 07:30:00', $result->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * The same candidate, resolved for storage. `anchorAt()` writes this
     * straight into `series_started_at`, so a clamped answer would move the
     * series off the day of the month its owner chose permanently.
     *
     * Killing mutation: have anchorOnOrAfter() delegate to onOrAfter(). The
     * assertion below then reads 2026-02-28.
     */
    public function test_an_anchor_resolved_from_a_past_monthly_date_skips_the_clamped_slot(): void
    {
        $result = (new Schedule)->anchorOnOrAfter(
            $this->at('2026-01-31 07:30:00'),
            $this->at('2026-02-10 12:00:00'),
            Recurrence::Monthly,
            'UTC',
        );

        $this->assertSame('2026-03-31 07:30:00', $result->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * A candidate already ahead of `now` is the day the owner named, reached
     * without walking a grid, so there is nothing to clamp and nothing to skip.
     */
    public function test_anchor_on_or_after_returns_a_future_candidate_untouched(): void
    {
        $result = (new Schedule)->anchorOnOrAfter(
            $this->at('2026-10-31 07:30:00'),
            $this->at('2026-09-14 12:00:00'),
            Recurrence::Monthly,
            'UTC',
        );

        $this->assertSame('2026-10-31 07:30:00', $result->utc()->format('Y-m-d H:i:s'));
    }

    /**
     * Only monthly has a day of the month to lose, so every other cadence must
     * resolve exactly as onOrAfter() resolves it — asserted against onOrAfter()
     * itself so the two cannot drift apart. The one-off is included because a
     * null recurrence returns the candidate untouched in both.
     */
    public function test_anchor_on_or_after_matches_on_or_after_for_every_other_cadence(): void
    {
        $schedule = new Schedule;
        $candidate = $this->at('2026-01-31 07:30:00');
        $now = $this->at('2026-02-10 12:00:00');

        foreach ([Recurrence::Daily, Recurrence::Weekdays, Recurrence::Weekly, Recurrence::Fortnightly, null] as $recurrence) {
            $this->assertTrue(
                $schedule->anchorOnOrAfter($candidate, $now, $recurrence, 'UTC')
                    ->equalTo($schedule->onOrAfter($candidate, $now, $recurrence, 'UTC')),
                ($recurrence?->value ?? 'once').' must resolve exactly as onOrAfter() says.',
            );
        }
    }
}
