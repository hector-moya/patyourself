# Fortnightly and Monthly Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `fortnightly` and `monthly` recurrences, and make monthly keep the day of the month it was anchored on.

**Architecture:** Monthly is the first recurrence where stepping from the previous slot and walking from the anchor disagree, so `Schedule::advance()` gains a non-nullable `$anchor` and the monthly arm computes from it. Everything else stays pairwise; `weekdays` must, because "the anchor plus n weekdays" is business-day counting. The recurrence vocabulary, currently spelled out by hand in ten places, collapses onto `Recurrence::tokens()` so two new cases cannot land in nine lists out of ten.

**Tech Stack:** PHP 8.4, Laravel 13, Carbon, PHPUnit 12; React 19, TypeScript, Inertia v3, Vitest + Testing Library.

**Spec:** `docs/superpowers/specs/2026-09-14-fortnightly-and-monthly-design.md`

**Prior specs on this branch, both merged and both binding:**
`2026-09-14-action-accordion-design.md`, `2026-09-14-choosing-a-start-date-design.md`.

## Global Constraints

- **Baseline: 1056 PHP tests / 6616 assertions, 530 JS tests, 0 TypeScript errors.** Both counts rise. `npm run build` must run before the PHP suite or `PwaManifestTest` skips itself and drops ~470 assertions.
- **No migration.** `actions.recurrence` is `string()->nullable()` with no constraint and already accepts the new tokens. Adding one is an Extra.
- **Banned vocabulary, comments included**, on every file in `CompanionVocabularyTest::sourceFiles()` — which includes `action-layer.tsx` and `cadence.ts`, and will include the new `recurrences.ts`: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` and `percent` are SUBSTRING traps — *endpoints* and *percentage* both trip the scanner.
- **Copy rules:** sentence case, no exclamation marks, never congratulating, no second person keeping score. The two new labels are exactly **Fortnightly** and **Monthly**.
- **Every new file under `resources/js/patyourself/loops/` goes on `CompanionVocabularyTest::sourceFiles()`.** A file absent from that list is scanned by nothing. That test asserts a count derived as `files × terms`, so adding a file changes the assertion total — expected.
- **Stored datetimes are UTC**; the user's IANA timezone localises them. Monthly's day-of-month must be read in the user's zone.
- **Run `npm run lint` from INSIDE the worktree**, never from the repository root — from the root eslint descends into `.claude/worktrees/` and silently `--fix`es tracked files.
- **Do not commit `package-lock.json`.** Running npm inside a worktree rewrites its `"name"` field to the worktree directory's basename; it must stay `"flat-landing"`. Check `git status` before committing and `git checkout -- package-lock.json` if npm touched it.
- **The unchanged-schedule guard must keep working.** `2026-09-13-amendable-action-layer-design.md` §3: the edit form posts the schedule on every save including a pure rename, and without the guard a rename purges the action's future occasions. `tests/Feature/Actions/SeriesAnchorTest.php` pins it.
- After any PHP change run `vendor/bin/pint --dirty --format agent`.
- Tests are SQLite, production is MySQL: bind values in any raw SQL, give every `ORDER BY` a tiebreaker.

## File Structure

| File | Responsibility |
| --- | --- |
| `app/Services/Scheduling/Recurrence.php` | The closed set of recurrence rules, and the authoring vocabulary derived from it. |
| `app/Services/Scheduling/Schedule.php` | Pure schedule maths. Its `advance()` learns the anchor, for the one arm that needs it. |
| `app/Services/Scheduling/MaterialiseOccurrences.php` | Walks an action's grid. Passes the anchor it is already walking from. |
| Four request classes + `AuthoredAction` + `DescribesActionShape` + `CreateLoopTool` | Validation surfaces. Each derives its accepted tokens rather than restating them. |
| `resources/js/patyourself/loops/recurrences.ts` | **New.** The client's one list: the options every select renders, and which recurrences ask for a date. |
| `resources/js/patyourself/loops/action-layer.tsx` | Two selects and the date-field rule. |
| `resources/js/patyourself/loops/start-experiment-form.tsx` | The third select. |

---

### Task 1: Two cases, and one vocabulary

**Files:**
- Modify: `app/Services/Scheduling/Recurrence.php`
- Test: `tests/Unit/Scheduling/RecurrenceTest.php`

**Interfaces:**
- Produces:
  ```php
  Recurrence::Fortnightly  // 'fortnightly'
  Recurrence::Monthly      // 'monthly'
  Recurrence::tokens(): array  // list<string> — 'once' plus every case's value
  ```
  Tasks 2, 3 and 4 all depend on these.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Scheduling/RecurrenceTest.php`, inside the class:

```php
    public function test_the_two_longer_cadences_map_from_their_tokens(): void
    {
        $this->assertSame(Recurrence::Fortnightly, Recurrence::tryFromToken('fortnightly'));
        $this->assertSame(Recurrence::Monthly, Recurrence::tryFromToken('monthly'));
    }

    /**
     * `once` is not a case — it maps to a null recurrence, which is what a
     * one-off is — but it is part of the vocabulary a person chooses from, so
     * it belongs in the list the validation surfaces derive from.
     */
    public function test_the_vocabulary_is_once_plus_every_case(): void
    {
        $tokens = Recurrence::tokens();

        $this->assertContains('once', $tokens);

        foreach (Recurrence::cases() as $case) {
            $this->assertContains($case->value, $tokens, "{$case->value} is missing from the vocabulary.");
        }

        $this->assertCount(count(Recurrence::cases()) + 1, $tokens);
    }

    /**
     * The vocabulary is what every request class validates against, so a
     * duplicate would show up as a repeated option in three select controls.
     */
    public function test_the_vocabulary_has_no_duplicates(): void
    {
        $tokens = Recurrence::tokens();

        $this->assertSame(array_values(array_unique($tokens)), $tokens);
    }

    public function test_once_is_still_a_one_off_rather_than_a_case(): void
    {
        $this->assertNull(Recurrence::tryFromToken('once'));
        $this->assertNull(Recurrence::tryFromToken(null));
        $this->assertNull(Recurrence::tryFromToken('fortnight'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=RecurrenceTest`
Expected: FAIL — `Recurrence::Fortnightly` undefined, `tokens()` undefined.

- [ ] **Step 3: Implement**

Replace the body of `app/Services/Scheduling/Recurrence.php`'s enum with:

```php
enum Recurrence: string
{
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';

    /**
     * Map an authored recurrence token to a case, or null for a one-off
     * ("once" / null / anything that is not a recurring rule).
     *
     * Delegates to the generated `tryFrom()` rather than listing the cases
     * again: a hand-written match is one more place a new case has to be
     * remembered, and forgetting it here would accept the token at the
     * boundary and then silently store a one-off. `once` is not a case, so it
     * falls through to null exactly as it always has.
     */
    public static function tryFromToken(?string $token): ?self
    {
        return $token === null ? null : self::tryFrom($token);
    }

    /**
     * Every token the authoring surfaces accept: the recurring rules, plus
     * `once` for a one-off.
     *
     * This is the one list. It was written out by hand in ten places — four
     * request classes, the authoring DTO, two MCP schemas and the client's
     * select controls — which is how a form comes to offer a cadence the API
     * refuses, with the suite green because nothing compared the lists.
     *
     * `once` leads because that is the order the controls read in, shortest
     * commitment first.
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return [
            'once',
            ...array_map(static fn (self $rule): string => $rule->value, self::cases()),
        ];
    }
}
```

Note the `tryFromToken()` simplification is behaviour-preserving for every input the old `match` handled: each case now maps through its own backing value, `'once'` and unknown strings still return null, and null short-circuits.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=RecurrenceTest`
Expected: PASS, including every pre-existing case in that file **unchanged** — they are what prove the `tryFromToken()` rewrite changed no behaviour.

- [ ] **Step 5: Confirm nothing downstream broke**

Run: `php artisan test --compact --filter=Scheduling`
Expected: PASS. Nothing consumes the new cases yet, so this is purely the equivalence check.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/Scheduling/Recurrence.php tests/Unit/Scheduling/RecurrenceTest.php
git commit -m "feat(scheduling): add fortnightly and monthly to the recurrence set

tryFromToken now delegates to the generated tryFrom instead of listing
every case again — a hand-written match is one more place a new case has
to be remembered, and forgetting it there would accept the token at the
boundary and then store a one-off.

tokens() becomes the authoring vocabulary. The same list is currently
written out by hand in ten places, which is how a form comes to offer a
cadence the API refuses with the suite green."
```

---

### Task 2: Monthly keeps its day of the month

**Files:**
- Modify: `app/Services/Scheduling/Schedule.php`
- Modify: `app/Services/Scheduling/MaterialiseOccurrences.php`
- Test: `tests/Unit/Scheduling/ScheduleTest.php`, `tests/Feature/` (materialise case — find the existing `MaterialiseOccurrences` test file and add to it)
- Modify: `docs/NOTEBOOK.md` §4

**Interfaces:**
- Consumes: `Recurrence::Fortnightly`, `Recurrence::Monthly` from Task 1.
- Produces:
  ```php
  Schedule::advance(CarbonImmutable $current, ?Recurrence $recurrence, string $timezone, CarbonImmutable $anchor): ?CarbonImmutable
  ```
  The fourth parameter is **non-nullable with no default**. Nothing later in this plan calls it.

- [ ] **Step 1: Write the failing tests**

There are **seven** existing `advance()` calls in `tests/Unit/Scheduling/ScheduleTest.php` (lines 59-62, 124, 136, 164). Each needs a fourth argument. For the pairwise recurrences the anchor is irrelevant, so pass `$current` — the same instant they already step from — and change nothing else about those tests.

Then append these cases to the class:

```php
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
     * server's. 23:30 on the 31st in Sydney is the 31st there and the 31st
     * *or* the 30th in UTC depending on the season — reading it in UTC would
     * anchor the series to the wrong day.
     */
    public function test_monthly_reads_the_day_of_the_month_in_the_owners_zone(): void
    {
        $schedule = new Schedule;
        // 2026-01-31 23:30 in Sydney is 2026-01-31 12:30 UTC.
        $anchor = $this->at('2026-01-31 12:30:00');
        $this->assertSame('31', $anchor->setTimezone('Australia/Sydney')->format('j'));

        $next = $schedule->advance($anchor, Recurrence::Monthly, 'Australia/Sydney', $anchor);

        $this->assertSame('28', $next->setTimezone('Australia/Sydney')->format('j'));
        $this->assertSame('23:30', $next->setTimezone('Australia/Sydney')->format('H:i'));
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
```

Then the grid-level case, appended to `tests/Feature/Scheduling/MaterialiseOccurrencesTest.php`:

```php
    /**
     * The unit test proves one step; this proves the walk. MaterialiseOccurrences
     * calls advance() repeatedly from the anchor, so a monthly action anchored
     * on the 31st must still be on the 31st in March — which it is only if the
     * anchor reaches advance() on every step, not just the first.
     */
    public function test_a_monthly_action_anchored_on_the_thirty_first_keeps_that_day(): void
    {
        $this->travelTo('2026-04-01 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $loop = Intention::factory()->for($user)->create(['status' => Intention::STATUS_ACTIVE]);
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'series_started_at' => CarbonImmutable::parse('2026-01-31 09:00:00'),
            'recurrence' => 'monthly',
            'metadata' => ['schedule_kind' => 'clock'],
        ]);

        app(MaterialiseOccurrences::class)->forLoop($loop);

        $this->assertDatabaseHas('occurrences', [
            'action_id' => $action->id,
            'scheduled_for' => '2026-03-31 09:00:00',
        ]);
        $this->assertDatabaseMissing('occurrences', [
            'action_id' => $action->id,
            'scheduled_for' => '2026-03-28 09:00:00',
        ]);
    }
```

Adapt its fixture setup and imports to whatever that file already does — read it first rather than assuming this shape compiles there.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=ScheduleTest`
Expected: FAIL — `ArgumentCountError`, because `advance()` takes three parameters.

- [ ] **Step 3: Give `advance()` the anchor**

In `app/Services/Scheduling/Schedule.php`, replace `advance()`:

```php
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
```

Also correct the class docblock's last sentence. It currently claims *"SP2's trigger engine reuses advance() after firing."* It does not — `TriggerEngine` claims and fires occasions that `MaterialiseOccurrences` has already written, and never touches this class. Replace that sentence with:

```php
 * `MaterialiseOccurrences` walks a grid with advance(); the trigger engine only
 * fires the occasions that walk already wrote, and never reaches this class.
```

- [ ] **Step 4: Pass the anchor from `nextAfter()`**

Still in `Schedule.php`, in `nextAfter()`, the loop becomes:

```php
        do {
            // `$from` is the series anchor at every call site — onOrAfter()
            // passes the candidate anchor, ReanchorsSeries the action's, and
            // StartExperiment the prior one — so it is what monthly needs.
            // Threading a separate parameter through here would add a way to
            // get that wrong without adding a case it gets right.
            $next = $this->advance($next, $recurrence, $timezone, $from);
        } while ($next !== null && $next->lessThanOrEqualTo($now));
```

- [ ] **Step 5: Pass the anchor from `MaterialiseOccurrences`**

In `app/Services/Scheduling/MaterialiseOccurrences.php`'s `materialise()`, hold the anchor beside the walking slot:

```php
        $anchor = $action->series_started_at->toImmutable();

        $slots = [];
        $slot = $anchor;

        while ($slot->lessThanOrEqualTo($horizon) && count($slots) < self::MAX_SLOTS_PER_ACTION) {
            $slots[] = $slot->utc()->toDateTimeString();

            // The anchor goes with every step, not just the first: monthly is
            // defined relative to it, so a walk that forgot it after the first
            // step would drift off the day of the month it started on.
            $next = $this->schedule->advance($slot, $recurrence, $timezone, $anchor);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=Scheduling`
Expected: PASS — the seven amended `advance()` calls, the new monthly and fortnightly cases, and every pre-existing scheduling test.

Then the writers that reach `advance()` through `nextAfter()`:

Run: `php artisan test --compact --filter="SeriesAnchorTest|ReanchorsSeries|StartExperiment|MaterialiseOccurrences"`
Expected: PASS, unchanged.

- [ ] **Step 7: Update `docs/NOTEBOOK.md` §4**

In the "### Materialising" subsection, after the bullet list's "**Bounded**" entry, add:

```markdown
**Monthly is anchored, not stepped.** Every other rule advances by an exact
duration, so stepping from the last slot and walking from the anchor agree. A
month is not a duration: stepping pairwise takes an action anchored on the 31st
to February's 28th and then leaves it there permanently. `Schedule::advance()`
therefore takes the anchor and computes monthly from it — clamping to the last
day of a short month and returning to the 31st in the next long one. `weekdays`
stays pairwise on purpose, because "the anchor plus n weekdays" is business-day
counting rather than calendar counting.
```

- [ ] **Step 8: Format, verify and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
```

```bash
git add app/Services/Scheduling/Schedule.php app/Services/Scheduling/MaterialiseOccurrences.php tests/Unit/Scheduling/ScheduleTest.php docs/NOTEBOOK.md
# plus the materialise feature test file you added to
git commit -m "feat(scheduling): keep a monthly series on its day of the month

advance() sees only the previous slot, and a month is not a duration, so
an action anchored on the 31st reached February's 28th and stayed on the
28th forever — one short month and it had silently changed what it meant.

It now takes the anchor, non-nullable and undefaulted so no caller can
quietly omit it, and the monthly arm computes from it. Weekdays stays
pairwise: the anchor plus n weekdays is business-day counting.

Also corrects this class's docblock, which claimed the trigger engine
reuses advance() after firing. It does not — it fires occasions the grid
walk already wrote."
```

---

### Task 3: Seven validation surfaces, one list

A batch of mechanical edits with one new test. Each site currently restates the vocabulary; each derives it instead.

**Files:**
- Modify: `app/Http/Requests/RescheduleActionRequest.php`, `app/Http/Requests/StoreActionRequest.php`, `app/Http/Requests/StoreExperimentRequest.php`, `app/Http/Requests/Api/RescheduleActionRequest.php`, `app/Services/Authoring/AuthoredAction.php`, `app/Concerns/DescribesActionShape.php`, `app/Mcp/Tools/CreateLoopTool.php`
- Test: `tests/Feature/Scheduling/RecurrenceVocabularyTest.php` (new), `tests/Feature/Actions/RescheduleActionWebTest.php`

**Interfaces:**
- Consumes: `Recurrence::tokens()` from Task 1.
- Produces: nothing new. After this task every PHP surface accepts `fortnightly` and `monthly`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Scheduling/RecurrenceVocabularyTest.php`:

```php
<?php

namespace Tests\Feature\Scheduling;

use App\Http\Requests\Api\RescheduleActionRequest as ApiRescheduleActionRequest;
use App\Http\Requests\RescheduleActionRequest;
use App\Http\Requests\StoreActionRequest;
use App\Http\Requests\StoreExperimentRequest;
use App\Services\Scheduling\Recurrence;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The check that would have caught the drift this consolidation removed.
 *
 * The recurrence vocabulary used to be written out by hand at every validation
 * surface, so adding a cadence meant editing each one and nothing noticed a
 * miss: the web form would offer a token the JSON API refused, with the suite
 * green. These assertions fail the day a surface stops agreeing with the enum,
 * including for the cadence nobody has thought of yet.
 */
class RecurrenceVocabularyTest extends TestCase
{
    /**
     * The field each request validates a recurrence under. StoreExperimentRequest
     * prefixes its action fields because it posts them alongside a strategy's.
     *
     * @return array<class-string, string>
     */
    private function surfaces(): array
    {
        return [
            RescheduleActionRequest::class => 'recurrence',
            StoreActionRequest::class => 'recurrence',
            ApiRescheduleActionRequest::class => 'recurrence',
            StoreExperimentRequest::class => 'action_recurrence',
        ];
    }

    public function test_every_surface_accepts_every_token_in_the_vocabulary(): void
    {
        foreach ($this->surfaces() as $request => $field) {
            foreach (Recurrence::tokens() as $token) {
                $validator = Validator::make(
                    [$field => $token],
                    (new $request)->rules(),
                );

                $this->assertFalse(
                    $validator->errors()->has($field),
                    "{$request} refuses the token '{$token}'.",
                );
            }
        }
    }

    public function test_every_surface_still_refuses_a_token_outside_the_vocabulary(): void
    {
        foreach ($this->surfaces() as $request => $field) {
            $validator = Validator::make(
                [$field => 'fortnight'],
                (new $request)->rules(),
            );

            $this->assertTrue(
                $validator->errors()->has($field),
                "{$request} accepts 'fortnight', which is not a recurrence.",
            );
        }
    }

    public function test_the_authoring_layer_accepts_the_two_new_cadences(): void
    {
        foreach (['fortnightly', 'monthly'] as $token) {
            $authored = \App\Services\Authoring\AuthoredAction::fromStructured([
                'title' => 'Deep clean',
                'schedule' => ['kind' => 'clock', 'time' => '09:00', 'recurrence' => $token],
            ]);

            $this->assertSame($token, $authored->recurrence);
        }
    }
}
```

`Validator::make()` runs only the rules array, so `withValidator()`'s "at least one field" check on `RescheduleActionRequest` never fires — which is what lets a bare recurrence be validated in isolation. Note that in a comment if it surprises you while reading.

If instantiating a `FormRequest` and calling `rules()` fails for one of these classes because its `rules()` reads request state, **say so and report it** rather than weakening the test — none of the four should, but verify rather than assume.

Then the end-to-end case, appended to `tests/Feature/Actions/RescheduleActionWebTest.php`. The vocabulary test proves the rule accepts the token; this proves the whole path — request, writer, and the grid walk the controller triggers — actually places a monthly series where the owner asked:

```php
    /**
     * The vocabulary test proves the token is accepted. This proves it lands:
     * a monthly series anchored on the 31st keeps that day, and materialises
     * nothing before the date the owner chose.
     */
    public function test_owner_can_start_a_monthly_series_on_a_chosen_day(): void
    {
        $this->travelTo('2026-09-14 12:00:00');

        $user = User::factory()->create(['timezone' => 'UTC']);
        $action = $this->actionFor($user);

        $this->actingAs($user)
            ->patch("/actions/{$action->id}", [
                'kind' => 'clock',
                'date' => '2026-10-31',
                'time' => '09:00',
                'recurrence' => 'monthly',
            ])
            ->assertRedirect();

        $action->refresh();

        $this->assertSame('monthly', $action->recurrence);
        $this->assertSame('2026-10-31 09:00:00', $action->series_started_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseMissing('occurrences', ['action_id' => $action->id]);
    }
```

`actionFor()` is that file's existing helper and it already imports everything this needs.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=RecurrenceVocabularyTest`
Expected: FAIL — every surface refuses `fortnightly` and `monthly`.

- [ ] **Step 3: The four request classes**

In each, replace the hardcoded rule with a derived one. `RescheduleActionRequest`, `StoreActionRequest` and `Api\RescheduleActionRequest` use the key `recurrence`; `StoreExperimentRequest` uses `action_recurrence`:

```php
'recurrence' => ['nullable', Rule::in(Recurrence::tokens())],
```

Add to each file's imports:

```php
use App\Services\Scheduling\Recurrence;
use Illuminate\Validation\Rule;
```

- [ ] **Step 4: `AuthoredAction`**

Delete `private const RECURRENCES = ['once', 'daily', 'weekdays', 'weekly'];` and change its one use:

```php
            if (! in_array($recurrence, Recurrence::tokens(), true)) {
```

Add `use App\Services\Scheduling\Recurrence;`.

- [ ] **Step 5: `DescribesActionShape`**

Delete `public const RECURRENCES = [...]` and replace its uses — the `->enum(self::RECURRENCES)` in this trait, and `Rule::in(self::RECURRENCES)` in both `AddActionTool` and `UpdateActionTool` — with `Recurrence::tokens()`. Add the import to each of the three files.

Confirm with `grep -rn "RECURRENCES" app/` that no other reference survives before deleting the const.

- [ ] **Step 6: `CreateLoopTool`**

Delete its `private const RECURRENCES` and replace both uses — the `Rule::in(...)` and the `->enum(...)` — with `Recurrence::tokens()`. Add the import.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=RecurrenceVocabularyTest`
Expected: PASS.

Then every surface that was edited:

Run: `php artisan test --compact --filter="ActionReschedule|RescheduleActionWeb|StoreAction|Experiment|CreateLoopTool|AddActionTool|UpdateActionTool|Authoring"`
Expected: PASS, unchanged.

- [ ] **Step 8: Format, verify and commit**

```bash
vendor/bin/pint --dirty --format agent
npm run build
php artisan test --compact
```

```bash
git add app/Http/Requests app/Services/Authoring/AuthoredAction.php app/Concerns/DescribesActionShape.php app/Mcp/Tools tests/Feature/Scheduling/RecurrenceVocabularyTest.php tests/Feature/Actions/RescheduleActionWebTest.php
git commit -m "refactor(scheduling): derive the recurrence vocabulary from the enum

Seven PHP surfaces each restated the list of acceptable recurrence
tokens. Adding a cadence meant editing all seven, and nothing noticed a
miss — the web form would offer a token the JSON API refused, with the
suite green.

They now derive from Recurrence::tokens(), and a test asserts each
surface accepts every token the enum knows. That check keeps working for
the cadence nobody has thought of yet."
```

---

### Task 4: The client offers them, and asks for a date

**Files:**
- Create: `resources/js/patyourself/loops/recurrences.ts`
- Modify: `resources/js/patyourself/loops/action-layer.tsx` (two selects, the date rule)
- Modify: `resources/js/patyourself/loops/start-experiment-form.tsx` (the third select)
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php` (`sourceFiles()`)
- Test: `resources/js/patyourself/loops/action-layer.test.tsx`

**Interfaces:**
- Consumes: nothing from earlier tasks at compile time — the client list is mirrored, not shared.
- Produces:
  ```ts
  export const RECURRENCES: ReadonlyArray<{ value: string; label: string }>
  export function namesADay(recurrence: string): boolean
  ```

- [ ] **Step 1: Write the failing tests**

Append to `resources/js/patyourself/loops/action-layer.test.tsx`, in the `describe('ActionEditor start date', …)` block or a new one beside it. Reuse that block's existing `weekly` fixture and `openEditor` helper — read them first and match their shape rather than declaring a second copy.

```tsx
describe('ActionEditor longer cadences', () => {
    it.each(['fortnightly', 'monthly'])(
        'asks for a start date on a %s action, which names a day',
        async (recurrence) => {
            await openEditor({ ...weekly, recurrence });

            expect(screen.getByLabelText('Starts on')).toBeInTheDocument();
        },
    );

    it('offers every cadence, longest commitment last', async () => {
        await openEditor(weekly);

        const select = screen.getByLabelText('How often');
        const offered = Array.from(
            select.querySelectorAll('option'),
        ).map((option) => option.value);

        expect(offered).toEqual([
            'once',
            'daily',
            'weekdays',
            'weekly',
            'fortnightly',
            'monthly',
        ]);
    });

    it('switches from monthly to daily and drops the start date', async () => {
        const user = await openEditor({ ...weekly, recurrence: 'monthly' });

        expect(screen.getByLabelText('Starts on')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('How often'), {
            target: { value: 'daily' },
        });

        expect(screen.queryByLabelText('Starts on')).not.toBeInTheDocument();
    });
});
```

That last case uses `fireEvent.change` for the reason the existing comment in this file records — read it before writing, and do not switch it to `userEvent.selectOptions`. Import `fireEvent` if the file does not already.

Add a case to `resources/js/patyourself/loops/cadence.test.ts` too:

```ts
    it.each(['fortnightly', 'monthly'])(
        'reads a %s cadence out with no special case',
        (recurrence) => {
            const next = '2026-09-16T07:30:00Z';
            const time = new Date(next).toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
            });

            expect(
                cadenceLabel({ ...base, recurrence, next_occurrence_at: next }),
            ).toBe(`${recurrence} at ${time}`);
        },
    );
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/loops/action-layer.test.tsx resources/js/patyourself/loops/cadence.test.ts`
Expected: FAIL — the selects offer four options, and no date input appears for the new cadences.

- [ ] **Step 3: Create the client's one list**

`resources/js/patyourself/loops/recurrences.ts`:

```ts
/**
 * The recurrences a person can choose, and which of them ask for a start date.
 *
 * Mirrored from `App\Services\Scheduling\Recurrence` on the server, not shared
 * with it — the same arrangement the workflow registries use, and with the same
 * caveat: nothing enforces that the two agree. A cadence added on the server
 * and missing here is simply never offered.
 *
 * `once` leads: shortest commitment first.
 */
export const RECURRENCES: ReadonlyArray<{ value: string; label: string }> = [
    { value: 'once', label: 'Once' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekdays', label: 'Weekdays' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'fortnightly', label: 'Fortnightly' },
    { value: 'monthly', label: 'Monthly' },
];

/**
 * Recurrences that ask for a start date, because a date names a *day* rather
 * than an instant and for these the day carries meaning: weekly and fortnightly
 * pick the weekday (and for fortnightly, which fortnight), monthly picks the
 * day of the month, and a one-off's date is the event itself.
 *
 * Daily and weekdays repeat on every day they apply to, so a date says nothing
 * about them — and posting no date is what keeps them on the server's
 * derived-anchor path.
 */
const NAME_A_DAY = ['once', 'weekly', 'fortnightly', 'monthly'];

export function namesADay(recurrence: string): boolean {
    return NAME_A_DAY.includes(recurrence);
}
```

- [ ] **Step 4: Put it on the vocabulary list**

In `tests/Feature/Companion/CompanionVocabularyTest.php`'s `sourceFiles()`, beside the existing `loops/` entries:

```php
            // The labels three select controls render, so it is where a
            // cadence gets its user-facing name.
            $root.'/resources/js/patyourself/loops/recurrences.ts',
```

- [ ] **Step 5: Drive the three selects from it**

In `resources/js/patyourself/loops/action-layer.tsx`, import:

```tsx
import { RECURRENCES, namesADay } from '@/patyourself/loops/recurrences';
```

Replace the hardcoded `<option>` children of **both** recurrence selects — the one in the add-an-action form and the one in `ActionEditor` — with:

```tsx
                                            {RECURRENCES.map((recurrence) => (
                                                <option
                                                    key={recurrence.value}
                                                    value={recurrence.value}
                                                >
                                                    {recurrence.label}
                                                </option>
                                            ))}
```

And replace `ActionEditor`'s date rule:

```tsx
    const needsDate = namesADay(recurrence);
```

Do the same option replacement for the third select in `resources/js/patyourself/loops/start-experiment-form.tsx`, importing `RECURRENCES` there.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `npx vitest run`
Expected: PASS — the new cases plus every pre-existing one. `CompanionVocabularyTest`'s assertion count rises by one file's worth; that is expected.

- [ ] **Step 7: Full verification**

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

Expected: PHP above 1056 / 6616, JS above 530, 0 TypeScript errors, pint and lint clean, test output pristine.

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/loops/recurrences.ts resources/js/patyourself/loops/action-layer.tsx resources/js/patyourself/loops/action-layer.test.tsx resources/js/patyourself/loops/cadence.test.ts resources/js/patyourself/loops/start-experiment-form.tsx tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(loops): offer fortnightly and monthly, and ask when they start

Three select controls each held their own copy of the cadence list. They
now render one, so a cadence cannot reach two of them and miss the third.

Both new cadences ask for a start date, because a date names a day and
for these the day carries meaning: fortnightly picks the weekday and
which fortnight, monthly picks the day of the month."
```

---

## Manual check

Not required for correctness — the tests are the gate — but the worktree is not served by Herd:

```bash
php -S 127.0.0.1:8899 -t public
```

Edit an action, choose Monthly, set a start date on the 31st, and confirm the cadence line reads "monthly from 31 Jan at 09:00" and that no occasion appears before the date.
