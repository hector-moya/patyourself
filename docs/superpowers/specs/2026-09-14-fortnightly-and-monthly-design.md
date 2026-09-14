# Fortnightly and monthly

**Date:** 2026-09-14
**Status:** design agreed, not yet implemented
**Scope:** the `Recurrence` enum and the ten places that spell its vocabulary by
hand, `Schedule::advance()`, and the editor's date rule.

The third of three specs on one branch. `2026-09-14-action-accordion-design.md`
and `2026-09-14-choosing-a-start-date-design.md` are merged into the branch
ahead of it; this one is only worth having because the second one exists — a
monthly action whose start date you cannot choose is a monthly action you cannot
place.

---

## 1. The problem

`Recurrence` has three cases: `daily`, `weekdays`, `weekly`. "Every other
Tuesday" and "the 1st of the month" are unsayable.

They are also the two cadences a habit notebook most obviously wants beyond
weekly, and the start-date picker has just made them placeable: the date names
which Tuesday the fortnight lands on, and which day of the month the monthly
one does.

## 2. Monthly is where pairwise arithmetic breaks

This is the ruling the whole spec turns on, and the reason this is a spec rather
than two enum cases.

`MaterialiseOccurrences` builds an action's grid by walking forward one step at
a time:

```php
$slot = $action->series_started_at;
while ($slot <= $horizon) {
    $slots[] = $slot;
    $slot = $this->schedule->advance($slot, $recurrence, $timezone);
}
```

`advance()` sees only **the previous slot**. For daily, weekly and fortnightly
that is enough, because their steps are exact durations — a day, a week, two
weeks — so walking from the anchor and stepping from the last slot agree
exactly.

**Months are not a duration.** With a pairwise `addMonthNoOverflow()`, an action
anchored on the 31st goes:

```
Jan 31 → Feb 28 → Mar 28 → Apr 28 → May 28 …
                   ^ the 31st is gone, permanently
```

One short February and the action has silently changed what it means, forever,
in an app whose whole purpose is keeping an honest record. `addMonth()` without
`NoOverflow` is worse — Jan 31 becomes Mar 3.

### The fix: monthly is defined by the anchor

```php
advance(CarbonImmutable $current, ?Recurrence $recurrence, string $timezone, CarbonImmutable $anchor): ?CarbonImmutable
```

```
anchor = Jan 31
  Jan 31 → Feb 28 → Mar 31 → Apr 30 → May 31 …
                     ^ clamps in a short month, then recovers
```

Monthly, and only monthly, reads `$anchor`:

```php
$elapsed = ($local->year - $anchorLocal->year) * 12
         + ($local->month - $anchorLocal->month);

return $anchorLocal->addMonthsNoOverflow($elapsed + 1)->utc();
```

**Plain calendar arithmetic, not `diffInMonths()`.** Carbon counts *complete*
months, so Jan 31 → Feb 28 is zero complete months — while it is unambiguously
one step of this grid. Deriving the step count from the calendar fields avoids
the question entirely.

**`$anchor` is non-nullable and has no default.** Both call sites have an anchor
to give. A defaulted parameter would let a future caller omit it and get silent
drift, which is the failure this method exists to prevent — the same argument
that made `RescheduleAction::handle()`'s `$date` a required parameter.

### Weekdays must stay pairwise

Anchor-relative arithmetic is wrong for `weekdays`: "the anchor plus n
weekdays" is business-day counting, not calendar counting, and would need a
different computation than every other arm. It keeps `addDay()` + `skipWeekend()`
and ignores `$anchor`, which is correct rather than an oversight.

So `$anchor` is a parameter every caller passes and one arm reads. That
asymmetry is deliberate and is worth a comment at the `match`.

### A clamped slot must never become the anchor

`ReanchorsSeries` and `StartExperiment` both **persist** the result of
`nextAfter()` as the new `series_started_at` — one on a timezone change, the
other on a strategy revision, neither because the owner picked a new day.

`nextAfter()` is right to return February's clamped 28th; it genuinely is the
next occasion. But storing it as an anchor makes the clamp permanent, because
the grid is computed from the anchor's day. That is this section's corruption
arriving by a different route.

`Schedule::nextAnchorAfter()` is what those two callers use instead: for monthly
it walks on to the next month that can hold the day the owner chose, giving up
that one occasion rather than the cadence. It is identical to `nextAfter()` for
every other cadence, because only monthly has a day of the month to lose.

> **Correction (2026-09-14).** "`ReanchorsSeries` and `StartExperiment` both" is
> wrong: there are **three** writers of `series_started_at`, not two, and the
> third is the one the owner actually drives. `Schedule::anchorAt()` resolves a
> chosen start date through `onOrAfter()` and `RescheduleAction` writes that
> result straight into the column, so a monthly action anchored on the 31st and
> edited during a short month was stored on the 28th — and so was one whose
> owner picked the 31st before the series had run at all.
>
> The list was drawn up by searching for callers of `nextAfter()` rather than
> for writers of `series_started_at`, which is why the action editor was missed.
>
> `Schedule::anchorOnOrAfter()` is the fix: `onOrAfter()`'s answer, walked on to
> a slot safe to persist, exactly as `nextAnchorAfter()` is `nextAfter()`'s.
> **`RescheduleAction::describesTheSameSchedule()` must call the same function**
> — the unchanged-schedule guard works only because both sides resolve
> identically, and leaving one side on `onOrAfter()` makes a pure rename purge a
> monthly action's future occasions during every short month.

### The two callers of `advance()`

This table is about `advance()`'s new `$anchor` parameter, not about who
persists an anchor — a distinction the correction above turns on.

| Caller | Passes as `$anchor` |
| --- | --- |
| `MaterialiseOccurrences::materialise()` | `$action->series_started_at` — the grid it is walking |
| `Schedule::nextAfter()` | its own `$from` |

`nextAfter()` needs no new parameter of its own: `$from` **is** the anchor at all
three of its call sites — `onOrAfter()` passes the candidate anchor,
`ReanchorsSeries` passes the action's anchor, and `StartExperiment` passes the
prior anchor. Threading a fourth parameter through it would add a way to get it
wrong without adding a case it gets right.

## 3. One vocabulary, spelled once

The set of authorable recurrence tokens is currently written out by hand in
**ten** places:

| Where | Form |
| --- | --- |
| `App\Services\Scheduling\Recurrence` | the enum itself — the source of truth |
| `App\Http\Requests\RescheduleActionRequest` | `'in:once,daily,weekdays,weekly'` |
| `App\Http\Requests\StoreActionRequest` | the same string |
| `App\Http\Requests\StoreExperimentRequest` | the same string |
| `App\Http\Requests\Api\RescheduleActionRequest` | the same string |
| `App\Services\Authoring\AuthoredAction` | `private const RECURRENCES` |
| `App\Concerns\DescribesActionShape` | `public const RECURRENCES` + a schema enum |
| `App\Mcp\Tools\CreateLoopTool` | its own `private const RECURRENCES` + a schema enum |
| `resources/js/patyourself/loops/action-layer.tsx` | two hardcoded `<option>` lists |
| `resources/js/patyourself/loops/start-experiment-form.tsx` | a third |

Adding two cases to ten lists by hand is how the web form comes to offer
`monthly` while the JSON API returns a 422 for it — with the suite green, because
nothing checks that the lists agree.

**`Recurrence::tokens()` becomes the one list**, and every PHP site derives from
it:

```php
/**
 * The tokens the authoring surfaces accept: every recurring rule, plus `once`
 * for a one-off. `once` is not a case — it maps to a null recurrence, as
 * tryFromToken() records — but it is part of the vocabulary a user chooses
 * from, so it belongs here rather than in each caller's copy of the list.
 */
public static function tokens(): array
```

The client keeps its own list, because the enum is PHP and the selects ship to
the browser. One exported const feeds all three selects, so the client side is
at least spelled once.

> **Correction (2026-09-14).** The workflow registries were cited here as the
> precedent for leaving the two lists unchecked. They are not one:
> `workflows.ts` maps names to React *components*, which cannot cross the wire,
> while this is `{value, label}` data — and `IntentionController` already sends
> the workflow *label* list down as a prop, with a comment saying a second
> client-side copy "could only ever drift out of agreement".
>
> Restructuring the recurrence list into a prop is a larger change than it is
> worth. The gap is closed cheaply instead:
> `RecurrenceVocabularyTest::test_the_clients_list_offers_exactly_the_servers_vocabulary`
> reads `recurrences.ts` and asserts its values against `Recurrence::tokens()`,
> in the same order — the mechanism `CompanionVocabularyTest` already uses to
> scan TypeScript from PHP.

This consolidation is in scope because it is what makes the change safe, not
because the duplication is untidy. It is the difference between "add two cases"
and "add two cases in ten places and hope".

**A test asserts every token in `Recurrence::tokens()` is accepted by every
request class that validates one.** That is the check that would have caught the
drift this consolidation removes, and it keeps working for the fourth cadence
nobody has thought of yet.

## 4. Which recurrences ask for a date

`2026-09-14-choosing-a-start-date-design.md` §2 rules that a date names a day,
so a recurrence gets a date field exactly when a day means something to it.
Both new cadences qualify:

| Recurrence | Asks for | Why |
| --- | --- | --- |
| `daily` | time only | every day — a date names nothing |
| `weekdays` | time only | every weekday — same |
| `weekly` | date + time | the date picks the weekday |
| **`fortnightly`** | date + time | the date picks the weekday **and which fortnight** |
| **`monthly`** | date + time | the date picks the day of the month |
| `once` | date + time | the date **is** the event |

The client's rule changes from naming two recurrences to naming four, so it
becomes an allowlist rather than a pair of comparisons.

Everything downstream already works. A past date snaps forward along the new
grids through `onOrAfter()`; the unchanged-schedule guard compares where two
schedules land, and both new cadences land somewhere; the one-off refusal is
unreachable for a non-null recurrence.

> **Correction (2026-09-14).** "Everything downstream already works" is false
> for monthly, for the reason §2's correction records: `onOrAfter()`'s answer is
> the right occasion and the wrong anchor. Both the snap and the guard now go
> through `anchorOnOrAfter()`, and they have to move together — see §2.

## 5. Copy

`fortnightly` and `monthly` read correctly through the existing formatter with
no special case: `cadenceLabel` prints the token, giving "fortnightly at 07:30"
and "monthly from 23 Sep at 07:30". The selects say **Fortnightly** and
**Monthly**.

> **Correction (2026-09-14).** Not quite: the "at 07:30" half came from
> `next_occurrence_at`, and the grid only reaches the end of the local day. A
> running monthly action therefore read as the bare word "monthly" on
> twenty-nine days in thirty — and the "from 23 Sep" line only applies before
> the series has begun. `cadenceLabel` now falls back to the anchor's own time,
> so a recurring action with no slot left today reads "monthly at 07:30". A
> one-off is unchanged: its occasion has gone and there is no cadence to
> qualify. Naming the *day* of a monthly action ("on the 31st") is a copy
> decision for the owner, not this wave.

`action-layer.tsx` and `cadence.ts` are on
`CompanionVocabularyTest::sourceFiles()`, so all new copy and comments avoid
`streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`,
`level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown` — `points`
and `percent` are substring traps, and *endpoints* and *percentage* both trip
them. Sentence case, no exclamation marks.

## 6. Two false statements to correct while here

Both are in files this spec changes, and both misdescribe the method whose
signature it changes.

**`Schedule`'s class docblock says "SP2's trigger engine reuses advance() after
firing."** It does not. `TriggerEngine` claims and fires occasions that
`MaterialiseOccurrences` has already written; it never touches `Schedule`. The
grid is built ahead of the cue, not advanced at the moment of one.

**`docs/NOTEBOOK.md` §4 says weekday and weekly maths is evaluated in the user's
zone "which is why `Schedule::advance()` round-trips through
`setTimezone($timezone)`".** True, and it now also carries monthly, whose
day-of-month must be read in the user's zone for the same reason.

## 7. Files

| File | Change |
| --- | --- |
| `app/Services/Scheduling/Recurrence.php` | two cases, `tokens()`, the false-docblock fix is next door |
| `app/Services/Scheduling/Schedule.php` | `advance()`'s `$anchor`, the monthly arm, the docblock correction |
| `app/Services/Scheduling/MaterialiseOccurrences.php` | passes the anchor |
| `app/Http/Requests/RescheduleActionRequest.php` | derives from `tokens()` |
| `app/Http/Requests/StoreActionRequest.php` | same |
| `app/Http/Requests/StoreExperimentRequest.php` | same |
| `app/Http/Requests/Api/RescheduleActionRequest.php` | same |
| `app/Services/Authoring/AuthoredAction.php` | same |
| `app/Concerns/DescribesActionShape.php` | same |
| `app/Mcp/Tools/CreateLoopTool.php` | same |
| `resources/js/patyourself/loops/recurrences.ts` | **new** — the client's one list |
| `resources/js/patyourself/loops/action-layer.tsx` | two selects, the date allowlist |
| `resources/js/patyourself/loops/start-experiment-form.tsx` | the third select |
| `docs/NOTEBOOK.md` | §4 gains the monthly ruling |

**No migration.** `actions.recurrence` is `string()->nullable()` with no
constraint, so the column already accepts the new tokens.

**`recurrences.ts` is new and goes on `CompanionVocabularyTest::sourceFiles()`.**
A file absent from that list is scanned by nothing, and this one holds the
user-facing labels.

## 8. Testing

**`Schedule::advance()` — the monthly arm is the point:**
- An anchor on the 31st steps Jan 31 → Feb 28 → **Mar 31**, recovering the day
  of the month. Killing mutation: use `$current` instead of `$anchor`, and March
  lands on the 28th.
- An anchor on the 30th steps Jan 30 → Feb 28 → Mar 30.
- An anchor on the 15th is unaffected by short months.
- February in a leap year clamps to the 29th, not the 28th.
- Monthly reads the day of the month in the **user's** zone, not the server's —
  a 23:30 anchor in Sydney is a different date in UTC.
- Fortnightly steps exactly two weeks and keeps its weekday.
- Weekdays still skips weekends and **ignores `$anchor`** — asserted by passing
  a deliberately unrelated anchor and getting the same answer.

**`MaterialiseOccurrences`:**
- A monthly action anchored on the 31st materialises the right slot in a short
  month, walking the real grid rather than one `advance()` call.

**`Recurrence::tokens()`:**
- Contains `once` and every case.
- Every token it lists is accepted by `RescheduleActionRequest`,
  `StoreActionRequest`, `StoreExperimentRequest` and
  `Api\RescheduleActionRequest` — one test, iterating, so the fourth cadence is
  covered the day it is added.
- A token outside the list is still refused by each.

**End to end:**
- An action rescheduled to `monthly` with a start date anchors there and
  materialises nothing before it.
- `update-action` and `create-loop` accept the two new tokens.

**New JS:**
- The date input appears for `fortnightly` and `monthly` as it does for `weekly`.
- All three selects offer the same six options, from the one const.

**Verification:**

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline entering: **1056 PHP tests / 6616 assertions, 530 JS tests, 0
TypeScript errors.** Both counts rise. `npm run build` must run first or
`PwaManifestTest` skips itself and drops ~470 assertions. `npm run lint` runs
inside the worktree — from the repository root eslint descends into
`.claude/worktrees/` and silently `--fix`es tracked files.

Tests are SQLite, production is MySQL: bind values in any raw SQL, and give
every `ORDER BY` a tiebreaker.

## 9. Out of scope

- **Anything finer than a day.** No "every 3 weeks", no "the second Tuesday",
  no hourly. `Recurrence` stays a closed set of named rules, which is what lets
  it be a validated enum rather than a parsed expression.
- **No end date.** A series runs until the action is retired.
- **`weekdays` does not become anchor-relative**, per §2.
- ~~**Nothing enforces that the server and client lists agree.**~~ *Closed
  2026-09-14, during this branch's final review: a PHP test reads
  `recurrences.ts` and holds it to `Recurrence::tokens()`. See §3's correction.*
- **The recurrence-switch pre-fill**, recorded during the previous spec's review:
  switching a long-running `daily` action to `weekly` pre-fills "Starts on" with
  a months-old anchor date. It affects the new cadences identically. Any fix must
  be narrow, because that past-date pre-fill is load-bearing for the
  unchanged-schedule guard's rename no-op.
