# Choosing a Start Date Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the action editor ask for a start date, not only a time of day — "start this on Wednesday the 23rd at 07:30, weekly".

**Architecture:** A date names a **day**, not an instant, so only the recurrences where a day means something ask for one: `weekly` and `once`. `daily` and `weekdays` post no date and keep today's derived-anchor path byte for byte, which is also the path the API and the MCP connector take. A past date is snapped forward onto its own grid rather than refused. The unchanged-schedule guard stops comparing time-of-day strings and starts asking whether the two schedules converge on the same next occurrence.

**Tech Stack:** PHP 8.4, Laravel 13, Carbon, PHPUnit 12; React 19, TypeScript, Inertia v3, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-09-14-choosing-a-start-date-design.md`

**Frozen prior record, still binding:** `docs/superpowers/specs/2026-09-13-amendable-action-layer-design.md` §3 — the unchanged-schedule guard. This plan extends it; it must not weaken it.

## Global Constraints

- **Baseline entering this plan: 1028 PHP tests / 6533 assertions, 511 JS tests, 0 TypeScript errors.** Both counts rise. `npm run build` must run before the PHP suite or `PwaManifestTest` skips itself and drops ~470 assertions.
- **Banned vocabulary, comments included**, on every file in `CompanionVocabularyTest::sourceFiles()` — which includes `resources/js/patyourself/loops/action-layer.tsx`: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` and `percent` are SUBSTRING traps — *endpoints* and *percentage* both trip the scanner. **Any new file under `resources/js/patyourself/loops/` or `resources/js/patyourself/training/` must be added to that list; a file absent from it is scanned by nothing.**
- **Copy rules:** sentence case, no exclamation marks, never congratulating, no second person keeping score. The date field's label is **"Starts on"**. The validation message is **"Pick a date that has not passed."**
- **Schedule form fields are unprefixed** — `kind`, `date`, `time`, `recurrence`, `anchor` — because that is what `RescheduleActionRequest` reads. `StartExperimentForm` uses `action_*` names for a different endpoint; do not copy those names.
- **Tests are SQLite, production is MySQL.** Bind values in any raw SQL; give every `ORDER BY` a tiebreaker.
- **Run `npm run lint` from INSIDE the worktree**, never from the repository root — from the root eslint descends into `.claude/worktrees/` and silently `--fix`es tracked files.
- **Do not commit `package-lock.json`.** Running npm inside a worktree rewrites its `"name"` field to the worktree directory's basename; it must stay `"flat-landing"`. Check `git status` before committing and `git checkout -- package-lock.json` if npm touched it.
- **Stored datetimes are UTC**; the user's IANA timezone localises them. Weekday and weekly maths is evaluated in the user's zone.
- After any PHP change, run `vendor/bin/pint --dirty --format agent` before committing.

## File Structure

| File | Responsibility |
| --- | --- |
| `app/Services/Scheduling/Schedule.php` | Pure schedule maths, no database. Gains `onOrAfter()` (snap a candidate onto its own grid) and `anchorAt()` (the anchor a user's edit describes). |
| `app/Actions/RescheduleAction.php` | The only place a reschedule writes. Gains a `$date` parameter, the convergence guard, and the one-off past-date refusal. |
| `app/Http/Requests/RescheduleActionRequest.php` | Shape of the web edit payload. Gains the `date` rule. |
| `app/Http/Controllers/ActionController.php` | Routes title to a plain update and schedule to the writer. Passes `date` through. |
| `app/Http/Controllers/IntentionController.php` | The loop screen's props. Sends `date` and `starts_at` per action. |
| `resources/js/patyourself/types.ts` | `ActionRecordData` — the action as the server sends it. |
| `resources/js/patyourself/loops/action-layer.tsx` | `ActionSummary` and the editor. Gains the date input and controlled recurrence. |
| `resources/js/patyourself/loops/cadence.ts` | The one cadence formatter. Learns "from 23 Sep at 07:30". |

---

### Task 1: The two pure functions

`Schedule` is pure date maths with no database, and both new methods belong there. This task adds them and their unit tests and nothing else — no caller changes yet.

**Files:**
- Modify: `app/Services/Scheduling/Schedule.php`
- Test: `tests/Unit/Scheduling/ScheduleTest.php`

**Interfaces:**
- Consumes: `Recurrence`, `Schedule::firstOccurrence()`, `Schedule::nextAfter()`, `Schedule::skipWeekend()` — all existing.
- Produces:
  ```php
  public function onOrAfter(CarbonImmutable $candidate, CarbonImmutable $now, ?Recurrence $recurrence, string $timezone): CarbonImmutable
  public function anchorAt(CarbonImmutable $now, ?string $localDate, ?string $localTime, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
  ```
  Task 2 calls both.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Scheduling/ScheduleTest.php`, inside the class. The file already has an `at()` helper returning `CarbonImmutable::parse($utc, 'UTC')` — use it.

```php
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
     * For daily and weekdays a date is inert: snapping a past one forward, one
     * period at a time, arrives exactly where the derived path would have put
     * it. That property is why a date reaching those recurrences by any route
     * needs no validation rule to make it safe.
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
     * The date is read in the user's zone, not the server's. 07:30 in London
     * on a British Summer Time date is 06:30 UTC.
     */
    public function test_anchor_at_reads_the_date_in_the_users_zone(): void
    {
        $anchor = (new Schedule)->anchorAt($this->at('2026-09-14 12:00:00'), '2026-09-23', '07:30', Recurrence::Weekly, 'Europe/London');

        $this->assertSame('2026-09-23 06:30:00', $anchor->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('07:30', $anchor->setTimezone('Europe/London')->format('H:i'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: FAIL with `Call to undefined method App\Services\Scheduling\Schedule::onOrAfter()` (and `anchorAt()`).

- [ ] **Step 3: Implement both methods**

In `app/Services/Scheduling/Schedule.php`, add after `nextAfter()` and before `skipWeekend()`:

```php
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
     * past one is {@see \App\Actions\RescheduleAction}'s decision, not this
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
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: PASS — the existing `firstOccurrence` / `advance` / `nextAfter` cases plus all eleven new ones.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
php artisan test --compact --filter=Scheduling
git add app/Services/Scheduling/Schedule.php tests/Unit/Scheduling/ScheduleTest.php
git commit -m "feat(scheduling): give Schedule an anchor a user can choose

A date names a day, not an instant, so a date that has passed snaps
forward onto its own grid instead of being refused: 'Wednesday, weekly'
means every Wednesday, and next Wednesday is what that names.

onOrAfter is the sibling of nextAfter rather than a wrapper: nextAfter
advances at least once, which would push every future start date one
period later. anchorAt delegates to firstOccurrence when no date is
given, which is the path daily, weekdays, the API and the connector all
keep taking."
```

---

### Task 2: The writer learns about dates

`RescheduleAction` gains the parameter, the convergence guard and the one-off refusal. Every call site is updated to pass `null` — including the web controller, which starts sending a real date in Task 3.

**Files:**
- Modify: `app/Actions/RescheduleAction.php`
- Modify: `app/Http/Controllers/ActionController.php:52-59`
- Modify: `app/Http/Controllers/Api/ActionController.php`
- Modify: `app/Mcp/Tools/UpdateActionTool.php:78-85`
- Test: `tests/Feature/Actions/SeriesAnchorTest.php` (9 existing `handle()` calls + new cases)
- Modify: `docs/NOTEBOOK.md` §4

**Interfaces:**
- Consumes: `Schedule::anchorAt()` and `Schedule::onOrAfter()` from Task 1.
- Produces:
  ```php
  RescheduleAction::handle(Action $action, string $kind, ?string $date, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action
  ```
  Task 3's web controller passes `$request->validated('date')` into the `$date` slot.

- [ ] **Step 1: Write the failing tests**

First, mechanically update the **nine** existing `app(RescheduleAction::class)->handle(...)` calls in `tests/Feature/Actions/SeriesAnchorTest.php` by inserting `null` as the third argument, immediately after the kind. For example:

```php
// before
app(RescheduleAction::class)->handle($action, 'clock', '19:30', 'daily', null, 'UTC');
// after
app(RescheduleAction::class)->handle($action, 'clock', null, '19:30', 'daily', null, 'UTC');
```

```php
// before
app(RescheduleAction::class)->handle($action, 'anchored', null, null, 'after dinner', 'UTC');
// after
app(RescheduleAction::class)->handle($action, 'anchored', null, null, null, 'after dinner', 'UTC');
```

Do **not** change any assertion in those tests. They pin today's derived-date behaviour and must keep pinning exactly that.

Then append these cases to the same class, above `tearDown()`:

```php
    /**
     * 2026-09-14 is a Monday; 2026-09-09, 2026-09-16, 2026-09-23 and
     * 2026-09-30 are the Wednesdays around it. The weekday assertions are
     * self-documenting anchors.
     */
    public function test_a_future_start_date_anchors_the_series_to_it(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-14 07:00:00'),
            'recurrence' => 'daily',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-23', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-23 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($rescheduled->series_started_at->isWednesday());
    }

    /**
     * A date names a day. Picking a Wednesday that has gone means the next
     * Wednesday, not a back-dated series — which would mint occasions nobody
     * was asked about, every one of them landing on /catch-up.
     *
     * Starts from a cue-anchored action so the result is observable: an action
     * already on the same weekly grid would converge with the guard and
     * correctly change nothing.
     */
    public function test_a_past_start_date_snaps_forward_onto_its_own_grid(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->anchored()->create();

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-09', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-16 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('occurrences', ['action_id' => $action->id]);
    }

    /**
     * The guard from the frozen amendable-action-layer spec §3, re-pinned under
     * the date branch. The edit form posts the schedule on every save, so a
     * pure rename resubmits the action's own anchor date — which is in the past
     * for any running weekly action.
     *
     * Killing mutation: drop the date branch from describesTheSameSchedule().
     * The occurrence below is unlogged and in the future, which is exactly what
     * purgeAbandonedOccurrences() removes.
     */
    public function test_resubmitting_a_past_anchor_date_unchanged_changes_nothing(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-09-16 07:30:00'),
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-09', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-09 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * Moving the start to a later date on the same weekday is a real change —
     * skipping a week — and must not read as "unchanged".
     *
     * Killing mutation: compare only time, recurrence and kind, as the guard
     * did before this branch existed. The action then keeps its old anchor and
     * the edit silently does nothing.
     */
    public function test_moving_the_start_to_a_later_date_reschedules(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-09-16 07:30:00'),
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-30', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-30 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('occurrences', ['action_id' => $action->id]);
    }

    /**
     * Two different past Wednesdays describe the same weekly series, so moving
     * between them changes nothing observable and must not purge and rebuild
     * the grid.
     *
     * Killing mutation: compare the submitted date string against the stored
     * anchor's date instead of comparing where the two schedules land. The
     * dates differ, so the guard misses and the occurrence below is purged.
     */
    public function test_a_different_past_date_on_the_same_grid_changes_nothing(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => Carbon::parse('2026-09-16 07:30:00'),
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-02', '07:30', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-09 07:30:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * Changing only the time on an action whose anchor has passed re-anchors
     * forward rather than leaving the series in the past. Refusing this was the
     * alternative design and it would have rejected an ordinary edit.
     */
    public function test_changing_only_the_time_on_a_past_anchor_moves_it_forward(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-09 07:30:00'),
            'recurrence' => 'weekly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-09', '08:00', 'weekly', null, 'UTC',
        );

        $this->assertSame('2026-09-16 08:00:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue($rescheduled->series_started_at->greaterThan(Carbon::now()));
    }

    /** A one-off has no grid to snap onto, so a date that has passed is refused. */
    public function test_a_one_off_cannot_be_moved_to_a_date_that_has_passed(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-20 09:00:00'),
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $this->expectException(ValidationException::class);

        app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-10', '09:00', 'once', null, 'UTC',
        );
    }

    /**
     * The refusal above sits *after* the unchanged-schedule guard, so a one-off
     * whose date has already passed can still be renamed — the save resubmits
     * its own date and returns before the refusal is reached.
     *
     * Killing mutation: move the past-date refusal above the guard. This test
     * then throws.
     */
    public function test_a_one_off_resubmitting_its_own_past_date_is_a_no_op(): void
    {
        Carbon::setTestNow('2026-09-14 12:00:00');

        $action = Action::factory()->create([
            'series_started_at' => Carbon::parse('2026-09-10 09:00:00'),
            'recurrence' => null,
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        $rescheduled = app(RescheduleAction::class)->handle(
            $action, 'clock', '2026-09-10', '09:00', 'once', null, 'UTC',
        );

        $this->assertSame('2026-09-10 09:00:00', $rescheduled->series_started_at->utc()->format('Y-m-d H:i:s'));
    }
```

Add `use Illuminate\Validation\ValidationException;` to the test file's imports if it is not already there. `Occurrence` and `Action` factories are already imported by the existing tests in this file — check before adding.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=SeriesAnchorTest`
Expected: FAIL — `ArgumentCountError` / too many arguments, because `handle()` still takes six parameters.

- [ ] **Step 3: Change the writer**

In `app/Actions/RescheduleAction.php`:

Inject `Schedule` alongside the existing dependency, and add the `ValidationException` import:

```php
use Illuminate\Validation\ValidationException;

final readonly class RescheduleAction
{
    public function __construct(private ReanchorsSeries $reanchor, private Schedule $schedule) {}
```

Replace the top of `handle()` down to the guard call with:

```php
    public function handle(Action $action, string $kind, ?string $date, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action
    {
        // One clock read for the whole method: the guard, the past-date refusal
        // and the purge must all agree about when "now" is.
        $now = CarbonImmutable::now();

        $rule = $kind === 'clock' ? Recurrence::tryFromToken($recurrence) : null;

        $scheduledFor = $kind === 'clock'
            ? $this->schedule->anchorAt($now, $date, $time, $rule, $timezone)
            : null;

        $metadata = array_merge($action->metadata ?? [], [
            'schedule_kind' => $kind,
            'anchor' => $kind === 'anchored' ? $anchor : null,
        ]);

        // The edit form posts title and schedule behind one Save, so the
        // schedule arrives on every save — including one that only changed the
        // title. Re-anchoring then would purge future occasions for a text
        // edit, so a schedule that describes what the action already has is
        // no reschedule at all.
        //
        // The guard lives here rather than in the client because a client that
        // forgot to diff would delete occasions silently, and the connector
        // reaches this writer by a different route. One place, both callers.
        if ($this->describesTheSameSchedule($action, $kind, $date, $scheduledFor, $time, $recurrence, $anchor, $timezone, $now)) {
            return $action;
        }

        // A one-off is its date, and it has no grid to snap onto, so a date
        // that has passed cannot be resolved into a sensible anchor the way a
        // recurring one can. Refused rather than stored, because storing it
        // materialises an occasion for a moment that is already gone.
        //
        // After the guard on purpose: a one-off whose date has passed must
        // still be renameable, and a rename resubmits that same past date.
        //
        // Unreachable without a date — firstOccurrence() cannot return a past
        // instant — and guarded on `$date` anyway so that stays true by
        // construction rather than by argument.
        if ($date !== null && $rule === null && $scheduledFor !== null && $scheduledFor->lessThanOrEqualTo($now)) {
            throw ValidationException::withMessages([
                'date' => 'Pick a date that has not passed.',
            ]);
        }
```

In the transaction below, replace the two `CarbonImmutable::now()` calls with `$now` (the `use` clause of the closure gains `$now`):

```php
        DB::transaction(function () use ($action, $scheduledFor, $rule, $metadata, $now): void {
            // …unchanged comment…
            $this->reanchor->purgeAbandonedOccurrences($action, $now);
```

Replace `describesTheSameSchedule()` entirely:

```php
    /**
     * Whether the submitted schedule describes the one the action already has.
     *
     * Two branches, because the anchor is computed two different ways.
     *
     * **Without a date** the anchor is derived from `now`, so comparing
     * resolved instants would never match: an action anchored last week that
     * resubmits its own time computes tomorrow's instant, and the guard would
     * then fire only for actions rescheduled minutes ago — the opposite of the
     * case it exists for. Compared on the description instead. The stored
     * anchor is localised to read its time of day; `setTimezone()` resolves the
     * offset in effect at that instant, so an anchor set at 17:30 in summer
     * still reads 17:30 in winter and a daylight saving change does not read as
     * an edit.
     *
     * **With a date** the computation is absolute, so the two schedules can be
     * compared on where they actually land: they are the same schedule when
     * they converge on the same next occurrence. That is stricter than a date
     * comparison in one direction and looser in the other, and both are
     * deliberate. Moving a weekly action to the Wednesday after next is a real
     * change even though the weekday is unchanged; moving it between two
     * Wednesdays that have both passed is not a change at all, because both
     * describe the same series and re-anchoring would purge and rebuild the
     * grid for something nobody could observe.
     *
     * Recurrence is compared before either branch, so both sides of the
     * convergence test walk the same grid — otherwise a weekly-to-daily change
     * could converge by accident.
     *
     * `once` and a null recurrence are the same thing — a one-off — so the
     * submitted token goes through `Recurrence::tryFromToken()` before the
     * comparison, exactly as `handle()` does when it stores it.
     */
    private function describesTheSameSchedule(
        Action $action,
        string $kind,
        ?string $date,
        ?CarbonImmutable $scheduledFor,
        ?string $time,
        ?string $recurrence,
        ?string $anchor,
        string $timezone,
        CarbonImmutable $now,
    ): bool {
        $metadata = $action->metadata ?? [];

        if (($metadata['schedule_kind'] ?? null) !== $kind) {
            return false;
        }

        if ($kind === 'anchored') {
            return ($metadata['anchor'] ?? null) === $anchor;
        }

        $rule = Recurrence::tryFromToken($recurrence);

        if ($action->recurrence !== $rule?->value) {
            return false;
        }

        if ($date === null) {
            return $action->series_started_at?->setTimezone($timezone)->format('H:i') === $time;
        }

        if ($action->series_started_at === null || $scheduledFor === null) {
            return false;
        }

        return $scheduledFor->equalTo(
            $this->schedule->onOrAfter($action->series_started_at, $now, $rule, $timezone),
        );
    }
```

Remove the now-unused `use App\Services\Scheduling\Schedule;`… actually **keep** it — `Schedule` is now a constructor-injected dependency, so the import is still needed. Remove only the `new Schedule` expression, which the rewritten `handle()` no longer contains.

- [ ] **Step 4: Update the three production call sites**

`app/Http/Controllers/ActionController.php` — insert `null` after the kind, with a comment; Task 3 replaces it:

```php
            $action = $reschedule->handle(
                $action,
                $request->validated('kind'),
                // No date yet: the edit form gains its date input in a later
                // task, and without one the writer derives the anchor exactly
                // as it always has.
                null,
                $request->validated('time'),
                $request->validated('recurrence'),
                $request->validated('anchor'),
                $request->user()->timezone ?? (string) config('app.timezone'),
            );
```

`app/Http/Controllers/Api/ActionController.php` — pass `null` in the same slot, with:

```php
                // The JSON API does not accept a start date. Passing null takes
                // the derived-anchor path, which is what this endpoint has
                // always done. Widening it is a separate decision about a
                // separate surface.
                null,
```

`app/Mcp/Tools/UpdateActionTool.php` — pass `null` in the same slot, with:

```php
                // The connector does not send a start date; null takes the
                // derived-anchor path `update-action` has always taken.
                null,
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=SeriesAnchorTest`
Expected: PASS — all pre-existing cases plus the eight new ones.

Then the neighbours that exercise the same writer:

Run: `php artisan test --compact --filter="RescheduleActionWebTest|ActionRescheduleTest|UpdateActionTool|IntentionScreensTest"`
Expected: PASS, unchanged.

- [ ] **Step 6: Update `docs/NOTEBOOK.md` §4**

After the "### Materialising" subsection and before "### Anchored actions have no grid", insert:

```markdown
### Choosing where the series starts

`Schedule::anchorAt()` resolves the anchor an edit describes. Without a date it
delegates to `firstOccurrence()` — the next occurrence at or after now with that
local time — which is what `daily` and `weekdays` take, and what the JSON API
and the MCP connector take, neither of which sends a date.

With a date, **the date names a day, not an instant.** "Wednesday, weekly" means
every Wednesday, so a date that has already passed is snapped forward onto its
own grid by `onOrAfter()` rather than refused. That is the sibling of
`nextAfter()`, not a wrapper: `nextAfter()` advances at least once, which would
push every future start date one period later.

**A back-dated anchor is never stored.** `MaterialiseOccurrences` walks from the
anchor to the end of the local day, so one would not record history — it would
mint occasions nobody was ever asked about, all unlogged, all landing on
`/catch-up`, against the reason §5's window exists at all.

**A one-off is the exception**, because it has no grid to snap onto: its date is
the whole schedule. A *changed* date in the past is refused with a validation
error. An *unchanged* one never reaches the refusal, because the unchanged-
schedule guard returns first — which is what keeps a one-off whose date has
passed renameable.

The add-an-action form is deliberately still time-only. A date there would reach
`AuthoredAction`, and through it loop creation, `StartExperiment` and the
connector's authoring pipeline. Add an action, then edit it to set a start date.
```

- [ ] **Step 7: Format, verify and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
```

Expected: 1028 PHP tests / 6533 assertions **plus** the new ones, all passing.

```bash
git add app/Actions/RescheduleAction.php app/Http/Controllers/ActionController.php app/Http/Controllers/Api/ActionController.php app/Mcp/Tools/UpdateActionTool.php tests/Feature/Actions/SeriesAnchorTest.php docs/NOTEBOOK.md
git commit -m "feat(actions): let a reschedule choose where the series starts

handle() takes a required nullable \$date. Required rather than
defaulted because every neighbouring parameter is ?string, so a trailing
optional would let an un-updated call site keep compiling while sliding
\$time into a slot that changed meaning.

The unchanged-schedule guard grows a second branch. With a date the
computation is absolute, so the two schedules are compared on where they
land rather than on their description: moving a weekly action to the
Wednesday after next is a real change, and moving it between two
Wednesdays that have both passed is not.

A one-off has no grid to snap onto, so a changed past date is refused —
after the guard, so a one-off whose date has passed stays renameable."
```

---

### Task 3: The endpoint sends a date, and the screen gets one back

**Files:**
- Modify: `app/Http/Requests/RescheduleActionRequest.php`
- Modify: `app/Http/Controllers/ActionController.php` (replace Task 2's `null`)
- Modify: `app/Http/Controllers/IntentionController.php:206-213`
- Modify: `resources/js/patyourself/types.ts` (`ActionRecordData`)
- Test: `tests/Feature/Actions/RescheduleActionWebTest.php`

**Interfaces:**
- Consumes: `RescheduleAction::handle()`'s `$date` slot from Task 2.
- Produces: each action in the loop screen's `actions` prop gains
  ```
  'date'       => string|null   // the anchor localised, Y-m-d — for the editor's input
  'starts_at'  => string|null   // the anchor localised, ISO 8601 — for the cadence line
  ```
  Task 4 reads `date`; Task 5 reads `starts_at`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Actions/RescheduleActionWebTest.php`:

The file already has a `private function actionFor(User $user): Action` helper and posts to the literal path `"/actions/{$action->id}"` rather than through `route()`. Use both. It imports `Action`, `Intention`, `Occurrence`, `Strategy`, `User`, `CarbonImmutable` and `RefreshDatabase` already — no new imports are needed.

```php
    public function test_owner_can_choose_the_date_the_series_starts(): void
    {
        $this->travelTo('2026-09-14 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'date' => '2026-09-23',
                'time' => '07:30',
                'recurrence' => 'weekly',
            ])
            ->assertRedirect();

        $this->assertSame(
            '2026-09-23 07:30:00',
            $action->fresh()->series_started_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_date_that_is_not_a_calendar_date_is_refused(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'date' => '23-09-2026',
                'time' => '07:30',
                'recurrence' => 'weekly',
            ])
            ->assertSessionHasErrors('date');
    }

    /**
     * "Start next Wednesday" must produce nothing until next Wednesday. The
     * controller materialises after a reschedule, so this asserts the real
     * path rather than the writer in isolation: MaterialiseOccurrences walks
     * from the anchor to the end of the local day and therefore writes no row
     * at all while the anchor is beyond that horizon.
     */
    public function test_a_future_start_date_materialises_no_occasions_yet(): void
    {
        $this->travelTo('2026-09-14 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'date' => '2026-09-23',
                'time' => '07:30',
                'recurrence' => 'weekly',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('occurrences', ['action_id' => $action->id]);
    }

    /**
     * A one-off cannot be started in the past, and the refusal reaches the
     * owner as a validation error on the field they chose it with rather than
     * as a 500.
     */
    public function test_a_one_off_cannot_be_started_on_a_date_that_has_passed(): void
    {
        $this->travelTo('2026-09-14 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'date' => '2026-09-10',
                'time' => '09:00',
                'recurrence' => 'once',
            ])
            ->assertSessionHasErrors('date');
    }
```

Then, for the screen's payload, **extend the existing test** `test_loop_detail_carries_its_live_actions_with_raw_scheduling_fields` in `tests/Feature/IntentionScreensTest.php` rather than adding a new one — it already owns the assertions on this payload's shape, and it already pins `actions.0.time` for exactly the reason this plan is extending it.

Its fixture anchors `$clockAction` at `series_started_at => '2026-08-25 19:00:00'` with a default-timezone (UTC) user, and `$anchoredAction` has no anchor at all. Add four `->where(...)` calls to the existing chain — the two clock ones immediately after the existing `->where('actions.0.time', '19:00')`, and the two anchored ones after `->where('actions.1.time', null)`:

```php
                // The anchor, in the two shapes the screen reads it in: the
                // date fills the editor's input, and the instant is what
                // cadenceLabel compares against now to tell a series that has
                // not begun from a grid that is merely exhausted for today.
                ->where('actions.0.date', '2026-08-25')
                ->where('actions.0.starts_at', '2026-08-25T19:00:00+00:00')
```

```php
                // A cue-anchored action has no anchor instant at all.
                ->where('actions.1.date', null)
                ->where('actions.1.starts_at', null)
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter="RescheduleActionWebTest|IntentionScreensTest"`
Expected: FAIL — the date is ignored (the anchor lands on the derived date, not the 23rd), `date` has no validation rule so no error is returned, and the Inertia props have no `date` / `starts_at` keys.

- [ ] **Step 3: Add the request rule**

In `app/Http/Requests/RescheduleActionRequest.php`, add to `rules()`, after `kind`:

```php
            // Only `weekly` and `once` render a date input, so most saves carry
            // none — and a save that carries none takes the derived-anchor path
            // this endpoint has always taken. Not added to withValidator()'s
            // "at least one field" set: `kind` already marks that a schedule was
            // submitted, and the form always sends `kind` alongside `date`.
            'date' => ['nullable', 'date_format:Y-m-d'],
```

- [ ] **Step 4: Pass it through the controller**

In `app/Http/Controllers/ActionController.php`, replace Task 2's `null` placeholder and its comment with:

```php
                $request->validated('date'),
```

- [ ] **Step 5: Send both fields to the screen**

In `app/Http/Controllers/IntentionController.php`, inside `actionLayer()`'s `map`, add after the existing `'time'` line:

```php
                // The anchor, twice, for two different readers. `date` fills
                // the editor's date input, which needs a Y-m-d string and must
                // not get one from client-side date maths: parsing an ISO
                // string in the browser's zone and reformatting is how a 23rd
                // becomes a 22nd for anyone west of the owner's zone.
                // `starts_at` is the instant, which is what the cadence line
                // compares against now to decide whether the series has begun.
                'date' => $action->series_started_at?->timezone($timezone)->format('Y-m-d'),
                'starts_at' => $action->series_started_at?->timezone($timezone)->toIso8601String(),
```

- [ ] **Step 6: Widen the client type**

In `resources/js/patyourself/types.ts`, in `ActionRecordData`, after the existing `time` field:

```ts
    /** The anchor's date in the owner's zone, `YYYY-MM-DD`. Null for a
     *  cue-anchored action, which has no anchor at all. Pre-formatted by the
     *  server because the editor's date input needs this exact string and
     *  re-deriving it from an ISO instant in the browser's zone moves it a day
     *  for anyone west of the owner. */
    date: string | null;
    /** The anchor as an instant, ISO 8601 in the owner's zone. The cadence line
     *  compares it against now to tell a series that has not begun from one
     *  whose grid is simply exhausted for today — both report no next
     *  occurrence, and they mean opposite things. */
    starts_at: string | null;
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter="RescheduleActionWebTest|IntentionScreensTest"`
Expected: PASS.

Then confirm nothing else regressed on the API and connector paths, which must be unaffected:

Run: `php artisan test --compact --filter="ActionRescheduleTest|UpdateActionTool"`
Expected: PASS, unchanged.

- [ ] **Step 8: Format, verify and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
npx tsc --noEmit
npm run lint
```

```bash
git add app/Http/Requests/RescheduleActionRequest.php app/Http/Controllers/ActionController.php app/Http/Controllers/IntentionController.php resources/js/patyourself/types.ts tests/Feature/Actions/RescheduleActionWebTest.php tests/Feature/IntentionScreensTest.php
git commit -m "feat(actions): accept a start date and send the anchor back

The web endpoint takes an optional date; the API and the connector still
send none and keep the derived-anchor path.

The loop screen gets the anchor twice, for two readers that want it in
two shapes: date for the editor's input, starts_at for the cadence line.
Both are formatted server-side in the owner's zone — deriving the date
from an ISO instant in the browser's zone moves it a day for anyone west
of the owner."
```

---

### Task 4: The editor asks for a date

**Files:**
- Modify: `resources/js/patyourself/loops/action-layer.tsx` (`ActionSummary`, `ActionEditor`)
- Modify: `resources/js/pages/loops/show.tsx:145-153`
- Test: `resources/js/patyourself/loops/action-layer.test.tsx`

**Interfaces:**
- Consumes: `ActionRecordData.date` / `.starts_at` from Task 3.
- Produces: `ActionSummary` gains `date: string | null` and `startsAt: string | null`, both **required**. Task 5 reads `startsAt` through `show.tsx`'s `cadenceLabel` call.

- [ ] **Step 1: Write the failing tests**

Append to the `describe('ActionLayer disclosure', …)` file — a new `describe` block at the end of `resources/js/patyourself/loops/action-layer.test.tsx`. Every existing fixture in the file will need `date: null` and `startsAt: null` added once the type is required; **add those keys without changing any existing assertion.**

```tsx
describe('ActionEditor start date', () => {
    const weekly = {
        id: 3,
        title: 'Weigh in',
        cadence: 'weekly at 07:30',
        scheduleKind: 'clock' as const,
        time: '07:30',
        recurrence: 'weekly',
        anchor: null,
        date: '2026-09-23',
        startsAt: '2026-09-23T07:30:00+01:00',
        routine: null,
    };

    async function openEditor(action: typeof weekly) {
        const user = userEvent.setup();
        render(<ActionLayer loopId={2} actions={[action]} />);
        await user.click(
            screen.getByRole('button', { name: `Edit ${action.title}` }),
        );

        return user;
    }

    it('asks for a start date on a weekly action, pre-filled from its anchor', async () => {
        await openEditor(weekly);

        const date = screen.getByLabelText('Starts on');

        expect(date).toHaveAttribute('name', 'date');
        expect(date).toHaveAttribute('type', 'date');
        expect(date).toHaveValue('2026-09-23');
    });

    it('asks for a start date on a one-off, whose date is the event', async () => {
        await openEditor({ ...weekly, recurrence: null, cadence: null });

        expect(screen.getByLabelText('Starts on')).toBeInTheDocument();
    });

    // "Every day at 07:00" — a date names nothing, so the field is not asked
    // for, and a save carrying no date takes the derived-anchor path.
    it('asks for no start date on a daily action', async () => {
        await openEditor({ ...weekly, recurrence: 'daily' });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });

    it('asks for no start date on a weekdays action', async () => {
        await openEditor({ ...weekly, recurrence: 'weekdays' });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });

    /**
     * Unmounting the input is the mechanism, not a side effect of one: with no
     * date in the payload the server derives the anchor exactly as it always
     * has.
     */
    it('removes the start date from the form when the recurrence stops needing one', async () => {
        const user = await openEditor(weekly);

        await user.selectOptions(screen.getByLabelText('How often'), 'daily');

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });

    it('asks for it again when the recurrence needs one once more', async () => {
        const user = await openEditor(weekly);

        await user.selectOptions(screen.getByLabelText('How often'), 'daily');
        await user.selectOptions(screen.getByLabelText('How often'), 'weekly');

        expect(screen.getByLabelText('Starts on')).toBeInTheDocument();
    });

    /**
     * An Inertia <Form> renders a real <form>, so native constraint validation
     * runs on submit. A `min` of today against a pre-filled past date would
     * block the pure rename this editor exists to allow — the rule is the
     * server's, and the server snaps a past date forward rather than refusing
     * it.
     */
    it('puts no minimum on the date input', async () => {
        await openEditor({ ...weekly, date: '2026-09-09' });

        expect(screen.getByLabelText('Starts on')).not.toHaveAttribute('min');
    });

    it('asks for no start date on a cue-anchored action', async () => {
        await openEditor({
            ...weekly,
            scheduleKind: 'anchored' as const,
            time: null,
            recurrence: null,
            anchor: 'after work',
            date: null,
            startsAt: null,
        });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/loops/action-layer.test.tsx`
Expected: FAIL — no element is labelled "Starts on".

- [ ] **Step 3: Widen `ActionSummary`**

In `resources/js/patyourself/loops/action-layer.tsx`, inside the existing schedule-fields docblock group, after `anchor`:

```ts
    /** The anchor's date in the owner's zone, `YYYY-MM-DD`, pre-formatted by
     *  the server. Required for the same reason its siblings above are: a
     *  caller that stopped passing it would silently put the editor's date
     *  input back on an empty default, and a save that changed only the title
     *  would then move the series. */
    date: string | null;
    /** The anchor as an instant, ISO 8601 in the owner's zone. Read by
     *  `cadenceLabel` to name a series that has not begun yet — see cadence.ts. */
    startsAt: string | null;
```

- [ ] **Step 4: Map them in `show.tsx`**

In `resources/js/pages/loops/show.tsx`, in the `actionSummaries` map, after `anchor`:

```tsx
        date: action.date,
        startsAt: action.starts_at,
```

- [ ] **Step 5: Add the control**

In `ActionEditor`, make the recurrence controlled. Beside the existing `kind` state:

```tsx
    // Controlled, unlike the add-an-action form's, because it decides whether
    // the date input is rendered at all: `daily` and `weekdays` repeat on every
    // day they apply to, so a date names nothing there, while `weekly` picks
    // the weekday and a one-off's date is the event itself.
    const [recurrence, setRecurrence] = useState<string>(
        action.recurrence ?? 'once',
    );

    const needsDate = recurrence === 'weekly' || recurrence === 'once';
```

Change the recurrence `<select>` from `defaultValue` to controlled:

```tsx
                                        <select
                                            id={`action-recurrence-${action.id}`}
                                            name="recurrence"
                                            value={recurrence}
                                            onChange={(e) =>
                                                setRecurrence(e.target.value)
                                            }
                                            className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        >
```

Then, immediately **after** the `<div className="flex gap-3">` that holds time and recurrence — still inside the `kind === 'clock'` branch — add:

```tsx
                                {needsDate && (
                                    <div className="space-y-1">
                                        <label
                                            htmlFor={`action-date-${action.id}`}
                                            className="ds-label"
                                        >
                                            Starts on
                                        </label>
                                        {/* No `min`: an Inertia <Form> is a
                                         *  real form, so native validation runs
                                         *  on submit, and a minimum of today
                                         *  against an anchor date that has
                                         *  passed would block the pure rename
                                         *  this form exists to allow. The
                                         *  server snaps a past date forward
                                         *  instead of refusing it. */}
                                        <input
                                            id={`action-date-${action.id}`}
                                            name="date"
                                            type="date"
                                            defaultValue={action.date ?? ''}
                                            className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        />
                                        {errors.date && (
                                            <p className="text-sm text-destructive">
                                                {errors.date}
                                            </p>
                                        )}
                                    </div>
                                )}
```

The `flex gap-3` wrapper closes before this block — the date sits on its own row beneath the time and recurrence, so the control that governs whether it appears is read first.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx vitest run resources/js/patyourself/loops/action-layer.test.tsx resources/js/pages/loops/show.test.tsx`
Expected: PASS. Fixtures in both files gain `date` / `startsAt`; no existing assertion changes.

- [ ] **Step 7: Verify and commit**

```bash
npx tsc --noEmit
npx vitest run
npm run lint
git status --porcelain   # package-lock.json must not appear
```

```bash
git add resources/js/patyourself/loops/action-layer.tsx resources/js/patyourself/loops/action-layer.test.tsx resources/js/pages/loops/show.tsx resources/js/pages/loops/show.test.tsx
git commit -m "feat(loops): ask when a weekly or one-off action starts

The recurrence select becomes controlled so the date input can mount
with it. Daily and weekdays repeat on every day they apply to, so a date
names nothing there and none is posted — which is the mechanism, not a
side effect: with no date the server derives the anchor as it always has.

The input carries no min. An Inertia Form is a real form, so native
validation runs on submit, and a minimum of today against an anchor date
that has passed would block the pure rename this form exists to allow."
```

---

### Task 5: The cadence line names a series that has not begun

**Files:**
- Modify: `resources/js/patyourself/loops/cadence.ts`
- Create: `resources/js/patyourself/loops/cadence.test.ts` — **`cadenceLabel` has no direct test today.** Every current assertion about a cadence string passes a pre-formatted value in as a prop, so this file is new, and it covers the pre-existing branches as well as the new one rather than being a partial view of the function.

**Interfaces:**
- Consumes: `ActionSummary.startsAt` from Task 4, passed through `show.tsx`'s existing `cadenceLabel(action)` call.
- Produces: nothing further depends on this.

- [ ] **Step 1: Write the failing tests**

**`vitest.config.ts` pins no `TZ`**, so `toLocaleDateString` / `toLocaleTimeString` render in whatever zone the machine is in. A hard-coded `'weekly from 23 Sep at 07:30'` would pass here and fail in CI or on a colleague's laptop.

The suite already has a convention for this — `resources/js/pages/loops/show.test.tsx:640-644` derives the expected time from the same ISO input with the same formatter, then interpolates it. Follow it. That makes the *formatting* self-referential and leaves the test asserting what actually matters: which branch is taken, and how the parts are composed.

Create `resources/js/patyourself/loops/cadence.test.ts`:

```ts
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { cadenceLabel } from './cadence';

const base = {
    schedule_kind: 'clock' as const,
    anchor: null,
    recurrence: 'weekly' as string | null,
    next_occurrence_at: null as string | null,
};

/**
 * The expected rendering of an instant, derived the way the suite already
 * derives it in show.test.tsx rather than hard-coded: vitest pins no TZ, so a
 * literal "23 Sep at 07:30" would pass on a UTC machine and fail everywhere
 * else. What is under test is which branch runs and how the parts are joined —
 * not whether Intl works.
 */
function renderedAs(iso: string): string {
    const date = new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
    const time = new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${date} at ${time}`;
}

describe('cadenceLabel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-14T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /**
     * A series that has not begun has materialised no occasions, so
     * next_occurrence_at is null and the line would otherwise read a bare
     * "weekly" — naming the cadence while saying nothing about the date that
     * was just chosen for it.
     */
    it('names the date a series has not reached yet', () => {
        const startsAt = '2026-09-23T07:30:00Z';

        expect(cadenceLabel({ ...base, starts_at: startsAt })).toBe(
            `weekly from ${renderedAs(startsAt)}`,
        );
    });

    it('names the date alone for a one-off, whose date is the event', () => {
        const startsAt = '2026-09-23T07:30:00Z';

        expect(
            cadenceLabel({ ...base, recurrence: null, starts_at: startsAt }),
        ).toBe(`from ${renderedAs(startsAt)}`);
    });

    /**
     * An anchor in the past means the series is running, so its next occasion
     * is the useful fact — not the day it started. With no occasion left in
     * today's grid there is genuinely nothing to name, and a bare "weekly" is
     * the correct answer rather than a gap to paper over.
     */
    it('says nothing about a start date the series has already passed', () => {
        expect(
            cadenceLabel({ ...base, starts_at: '2026-09-09T07:30:00Z' }),
        ).toBe('weekly');
    });

    it('prefers the next occurrence once the series is running', () => {
        const next = '2026-09-16T07:30:00Z';
        const time = new Date(next).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-09T07:30:00Z',
                next_occurrence_at: next,
            }),
        ).toBe(`weekly at ${time}`);
    });

    it('is unchanged when no start date is carried at all', () => {
        const next = '2026-09-16T07:30:00Z';
        const time = new Date(next).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        expect(cadenceLabel({ ...base, next_occurrence_at: next })).toBe(
            `weekly at ${time}`,
        );
    });

    it('describes a cue-anchored action by its phrase', () => {
        expect(
            cadenceLabel({
                schedule_kind: 'anchored',
                anchor: 'brushing my teeth',
                recurrence: null,
                next_occurrence_at: null,
                starts_at: null,
            }),
        ).toBe('after brushing my teeth');
    });

    // The defect this function was fixed for once already: a recurrence with
    // no time left to report must not render as a dangling "weekly at ".
    it('renders no dangling cadence when there is nothing to name', () => {
        expect(cadenceLabel({ ...base, starts_at: null })).toBe('weekly');
        expect(
            cadenceLabel({ ...base, recurrence: null, starts_at: null }),
        ).toBeNull();
    });
});
```

`renderedAs()` builds only the `"23 Sep at 07:30"` half; each expectation supplies the prefix it is actually asserting.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/loops/cadence.test.ts`
Expected: FAIL — `starts_at` is not part of `CadenceSource`, and the label ignores it.

- [ ] **Step 3: Implement**

In `resources/js/patyourself/loops/cadence.ts`, widen the source type:

```ts
/** The scheduling fields any cadence description is built from. */
type CadenceSource = Pick<
    ActiveActionData,
    'schedule_kind' | 'anchor' | 'recurrence' | 'next_occurrence_at'
> & {
    /**
     * The series anchor as an instant, ISO 8601. Optional because
     * `currentCadenceLabel`'s caller reads `ActiveActionData`, which does not
     * carry one — absent there, this behaves exactly as it did before.
     */
    starts_at?: string | null;
};
```

Extend the function, between the anchored branch and the existing time handling:

```ts
export function cadenceLabel(action: CadenceSource): string | null {
    if (action.schedule_kind === 'anchored') {
        return action.anchor === null ? null : `after ${action.anchor}`;
    }

    // A series that has not begun has materialised no occasions, so
    // `next_occurrence_at` is null and the lines below would read a bare
    // "weekly" — naming the cadence while saying nothing about the date that
    // was chosen for it. The anchor is what such an action is waiting on, so
    // it is what the line names.
    //
    // Compared as instants rather than as date strings, so the answer does not
    // depend on the browser's zone. A *past* anchor means the series is
    // running, and then the next occasion is the right thing to name — or
    // nothing, for a grid already exhausted for today.
    const startsAt = action.starts_at ?? null;

    if (startsAt !== null && new Date(startsAt) > new Date()) {
        const start = `from ${formatDate(startsAt)} at ${formatTime(startsAt)}`;

        return action.recurrence === null ? start : `${action.recurrence} ${start}`;
    }

    const time =
        action.next_occurrence_at === null
            ? null
            : formatTime(action.next_occurrence_at);

    if (action.recurrence !== null && time !== null) {
        return `${action.recurrence} at ${time}`;
    }

    return action.recurrence ?? time;
}
```

And add beside `formatTime`:

```ts
function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run resources/js/patyourself/loops/cadence.test.ts resources/js/pages/loops/show.test.tsx`
Expected: PASS.

- [ ] **Step 5: Full verification**

From inside the worktree, in this order:

```bash
npm run build
php artisan test --compact
npx vitest run
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
npm run lint
git status --porcelain   # package-lock.json must not appear
```

Expected: PHP above 1028 / 6533, JS above 511, 0 TypeScript errors, pint and lint clean, test output pristine.

- [ ] **Step 6: Commit**

```bash
git add resources/js/patyourself/loops/cadence.ts resources/js/patyourself/loops/cadence.test.ts
git commit -m "feat(loops): name the date a series has not reached yet

A future-anchored action materialises no occasions, so the cadence line
had no time to report and read a bare 'weekly' — the start date the
owner had just chosen was invisible, which made the picker look broken.

Compared as instants, not date strings, so the browser's zone cannot
move the answer. A past anchor still yields to the next occurrence,
because then the series is running and that is the useful fact."
```

---

## Manual check

Not required for correctness — the tests are the gate — but this is a visible change and the worktree is not served by Herd:

```bash
php -S 127.0.0.1:8899 -t public
```

Open a loop, edit an action, switch the recurrence between daily and weekly and watch the date field come and go. Set a start date a week out and confirm the cadence line reads "weekly from … at …" and the action produces no occasion until then.
