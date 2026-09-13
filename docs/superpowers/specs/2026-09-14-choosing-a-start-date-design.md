# Choosing a start date

**Date:** 2026-09-14
**Status:** design agreed, not yet implemented
**Scope:** `Schedule`, `RescheduleAction` and its request, the loop screen's
action payload, and the action editor's schedule half.

The second of three specs on one branch. `2026-09-14-action-accordion-design.md`
lands first and restructures the rows this one writes into.
`2026-09-13-amendable-action-layer-design.md` is the frozen record of the editor
being changed, and its §3 is the guard this spec extends rather than replaces.

---

## 1. The problem

The editor asks for a time of day and a recurrence. It cannot ask for a date, so
"start this on Tuesday the 23rd at 07:30, weekly" is unsayable. `RescheduleAction`
derives the date instead: `Schedule::firstOccurrence(now(), …)` resolves to the
next occurrence at or after now with that local time, and the user never sees
the choice being made for them.

## 2. The date names a day, not an instant

This is the ruling everything else follows from, and it is the user's own
framing: *"I select Wednesday and then I say weekly, meaning it should happen
every Wednesday at the same time every week."*

A date is how you say **which day of the cycle** an action lands on. It is not a
historical timestamp and it is not a separate fact from the recurrence. So which
recurrences ask for one falls straight out:

| Recurrence | Asks for | Why |
| --- | --- | --- |
| `daily` | time only | every day — a date names nothing |
| `weekdays` | time only | every weekday — same |
| `weekly` | date + time | the date picks the weekday |
| `once` | date + time | the date **is** the event |

`anchored` actions have no clock at all and are unchanged.

This has a consequence worth stating plainly, because it is what keeps the
change small: **daily and weekdays post no `date` field at all**, so they take
the existing `firstOccurrence` path, byte for byte. That is the same path the
API and the MCP connector take, since neither sends a date either. The new
behaviour is reachable only from `weekly` and `once`, on the web editor.

## 3. A past date is snapped forward, not refused

Picking a Wednesday that has gone is not an error, because the user was naming a
weekday. It resolves to the next Wednesday at that time.

The alternative — refusing it — was the earlier recommendation and is worse. The
editor pre-fills from the action's stored anchor (§5), and a running weekly
action's anchor is almost always in the past, so refusal would reject the
ordinary edit of moving 07:30 to 08:00 until the user also picked a new date for
no reason they could see.

**Accepting a past date verbatim is not an option.** `MaterialiseOccurrences`
walks from the anchor to the end of the local day, bounded only by
`MAX_SLOTS_PER_ACTION = 1000`. A daily action anchored a year back does not
record history — it **mints hundreds of occasions nobody was ever asked about**,
every one unlogged, every one landing on `/catch-up`. `docs/NOTEBOOK.md` §5 is
explicit that the today-window exists so the record cannot become a backlog and
the digest cannot become a nag; back-dating an anchor is the shortest route to
exactly that.

**`once` is the exception, because it has no grid to snap onto.** A one-off is
its date. A *changed* date in the past is refused with a validation error; an
*unchanged* one never reaches the check, because §4's guard returns first.

## 4. Two pure functions, and a guard that becomes a convergence test

### `Schedule::onOrAfter()`

```php
onOrAfter(CarbonImmutable $candidate, CarbonImmutable $now, ?Recurrence $recurrence, string $timezone): CarbonImmutable
```

The candidate if it is after `now`; otherwise the candidate walked forward along
its own grid until it is. **A null recurrence returns the candidate untouched** —
a one-off has no grid, and this is the single place that fact is expressed.

It delegates the walk to the existing `nextAfter()` rather than repeating it.
The two are not the same function: `nextAfter()` advances at least once
unconditionally, which is correct for "the slot after this one fired" and wrong
here, where a candidate already in the future must come back unchanged. Calling
`nextAfter()` directly would silently push every future date one period later.

### `Schedule::anchorAt()`

```php
anchorAt(CarbonImmutable $now, ?string $localDate, ?string $localTime, ?Recurrence $recurrence, string $timezone): ?CarbonImmutable
```

- `$localTime === null` → null, as `firstOccurrence()` does. An anchored action
  has no clock.
- `$localDate === null` → **delegates to `firstOccurrence()`**. Unchanged path,
  unchanged behaviour, and the only path daily, weekdays, the API and the
  connector ever take.
- otherwise → builds `$localDate $localTime` in the user's zone, applies the
  `weekdays` weekend bump `firstOccurrence()` already applies, and returns it
  through `onOrAfter()`.

The weekend bump is kept rather than dropped as unreachable. No web form sends a
date alongside `weekdays`, but `advance()` never produces a weekend slot, so an
anchor on one would put the whole grid half a step off its own rule. Handling it
costs one line and removes a class of question.

**A property worth naming, because it is what makes the change safe:** for
`daily` and `weekdays`, `anchorAt()` with a date converges on exactly what
`firstOccurrence()` returns without one. Snapping a past daily date forward day
by day *is* "that time, today or tomorrow". So a date reaching those recurrences
by any route — a crafted request, a future form — is inert rather than
surprising, and needs no validation rule to make it so.

### The guard

`RescheduleAction::describesTheSameSchedule()` gains one branch, for clock with
a date. It is not a date comparison. It asks whether the two schedules **land on
the same next occurrence**:

```php
$action->recurrence === Recurrence::tryFromToken($recurrence)?->value
    && $action->series_started_at !== null
    && $scheduledFor?->equalTo($this->schedule->onOrAfter(
        $action->series_started_at, $now, $rule, $timezone,
    ))
```

Recurrence is compared first and separately, so both sides snap along the same
grid — otherwise a weekly-to-daily change could converge by accident.

That single comparison does all four jobs:

| Save | Stored anchor snaps to | Submitted snaps to | Outcome |
| --- | --- | --- | --- |
| rename, prefilled past date | next Wed 07:30 | next Wed 07:30 | **no-op** — §3 of the frozen spec holds |
| 07:30 → 08:00 on an old action | next Wed 07:30 | next Wed 08:00 | reschedules, no past occasions |
| date → the Wednesday after next | Wed 23rd | Wed 30th | **a real change** |
| some other past Wednesday | next Wed | next Wed | no-op, correctly — same series |

Row three is the failure this spec exists to avoid: a guard that compared time,
recurrence, kind and anchor phrase — today's — would read a pure date change as
"unchanged" and silently do nothing.

Row four is why the comparison is on the resolved occurrence rather than on the
submitted date string. Two different past Wednesdays describe the same weekly
series, and re-anchoring between them would purge and rebuild the grid for no
change a user could observe.

**The no-date branch is untouched.** Its docblock explains that
`firstOccurrence()` resolves relative to `now`, so an action anchored last week
resubmitting its own time computes tomorrow and would never match a resolved
anchor. That reasoning is still exactly right — for the derived-date case. With
a date the computation is absolute, so resolved-instant comparison is available
and is strictly more precise. Both branches stay, each with its own reason.

### The `once` refusal

After the guard, before the transaction:

```php
if ($date !== null && $rule === null && $scheduledFor?->lessThanOrEqualTo($now)) {
    throw ValidationException::withMessages(['date' => 'Pick a date that has not passed.']);
}
```

Guarded on `$date !== null` as well as on the null recurrence, so the derived
path — which cannot produce a past instant — is provably unreachable from here
rather than merely unlikely to reach it.

It lives in the writer beside the unchanged-schedule guard, for the reason §3 of
the frozen spec gives for that one: the connector reaches this writer by another
route, and a rule enforced in the request would protect one caller.

## 5. `handle()` takes a required nullable `$date`

```php
handle(Action $action, string $kind, ?string $date, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action
```

**Required, not defaulted**, and positioned after `$kind`. Every parameter around
it is `?string`, so a defaulted parameter appended at the end would let an
un-updated call site keep compiling while silently passing `$time` into a slot
that now means something else. A required parameter in the middle makes every
one of them an `ArgumentCountError` on the spot.

Call sites to update: `ActionController@update`, `Api\ActionController@update`
and `Mcp\Tools\UpdateActionTool` all pass `null`. Roughly ten direct `handle()`
calls in `tests/Feature/Actions/SeriesAnchorTest.php` pass `null` too — they
assert today's derived-date behaviour and must keep asserting exactly that.

**The API and the connector keep passing null.** Widening
`Api\RescheduleActionRequest` and `update-action` is a separate decision about a
separate surface, recorded here rather than taken. With null they behave
identically to today, which is the point of the delegating branch in §4.

## 6. What the screen sends and shows

`IntentionController::actionLayer()` gains two fields per action:

| Field | Value | For |
| --- | --- | --- |
| `date` | `series_started_at` localised, `Y-m-d` | the editor's date input |
| `starts_at` | `series_started_at` localised, ISO 8601 | the cadence line |

Both are formatted on the server in the user's own zone. The editor's input
needs a `Y-m-d` string and must not get it from client-side date math: parsing
an ISO string in the browser's zone and reformatting is how a 23rd becomes a
22nd for anyone west of the stored zone.

`ActionSummary` gains `date: string | null` and `startsAt: string | null`,
**required rather than optional**, for the reason commit `42e486b` gives for the
existing schedule fields: a caller that stopped passing them would silently put
the editor back on its own defaults, and a title-only save would then reschedule
the action.

### The cadence line

A future-dated action materialises nothing — correctly — so `nextOccurrenceAt()`
returns null and `cadenceLabel()` renders a bare `"weekly"`. Set a start date for
next Tuesday and the screen says nothing about Tuesday. The feature would look
like it had failed.

So `cadenceLabel()` learns an optional `starts_at`: when the anchor is still in
the future, it reads **"weekly from 23 Sep at 07:30"**.

The future test is an instant comparison — `new Date(starts_at) > new Date()` —
not a date-string comparison, so it is correct whatever zone the browser is in.
The field is optional, so `currentCadenceLabel()`'s `ActiveActionData` callers
pass nothing and behave exactly as they do now. `IntentionResource` is not
widened to match; it can be, when something needs it.

## 7. The editor

`ActionEditor`'s recurrence select becomes controlled state, like `kind` already
is, so the date input can mount and unmount with it.

```
EDITING, weekly                     EDITING, daily
  [ Upper body 2___________ ]         [ Weigh in_______________ ]
  ( ) At a time                       ( ) At a time
      [2026-09-23] [07:30]                         [07:00]
      [weekly ▾]                          [daily ▾]
  (•) After a cue [ ________ ]        (•) After a cue [ ________ ]
          Save   Cancel                       Save   Cancel
```

- The date input is rendered only for `weekly` and `once`. Switching to `daily`
  unmounts it, so no `date` is posted, so the derived path runs — the unmount
  *is* the mechanism, not a side effect of one.
- `defaultValue={action.date ?? ''}`, so a save that changes nothing else posts
  the action's own anchor date and the guard recognises it.
- **No `min` attribute.** An Inertia `<Form>` renders a real `<form>`, so native
  constraint validation runs on submit; a `min` of today against a pre-filled
  past date would block the pure rename this editor exists to allow. The rule
  is the server's, and the server snaps rather than refuses.
- Field names stay unprefixed — `kind`, `date`, `time`, `recurrence`, `anchor` —
  because that is what `RescheduleActionRequest` reads. `StartExperimentForm`'s
  `action_*` names are for a different endpoint.

`RescheduleActionRequest` gains `'date' => ['nullable', 'date_format:Y-m-d']`.
It is not added to `withValidator()`'s "at least one field" set: `kind` already
marks that a schedule was submitted, and the form always sends `kind` alongside
`date`.

### Copy

`action-layer.tsx` is on `CompanionVocabularyTest::sourceFiles()`, so all new
copy and comments avoid `streak`, `congratulation`, `well done`,
`completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`,
`misses you`, `neglect`, `cooldown` — `points` and `percent` are substring
traps, and *endpoints* and *percentage* both trip them. Sentence case, no
exclamation marks.

The date field's label is **"Starts on"**. The validation message is
**"Pick a date that has not passed."**

## 8. Files

| File | Change |
| --- | --- |
| `app/Services/Scheduling/Schedule.php` | `onOrAfter()`, `anchorAt()` — new |
| `app/Actions/RescheduleAction.php` | `$date` parameter, the guard's new branch, the `once` refusal |
| `app/Http/Requests/RescheduleActionRequest.php` | `date` rule |
| `app/Http/Controllers/ActionController.php` | passes `date` through |
| `app/Http/Controllers/Api/ActionController.php` | passes `null` |
| `app/Mcp/Tools/UpdateActionTool.php` | passes `null` |
| `app/Http/Controllers/IntentionController.php` | `date` and `starts_at` on the action payload |
| `resources/js/patyourself/loops/action-layer.tsx` | the date input, controlled recurrence |
| `resources/js/patyourself/loops/cadence.ts` | optional `starts_at`, "from …" |
| `resources/js/pages/loops/show.tsx` | maps the two new fields |
| `tests/Feature/Actions/SeriesAnchorTest.php` | ~10 `handle()` calls gain `null` |
| `docs/NOTEBOOK.md` | §4 gains the date, the snap rule and the `once` refusal |

No migration. No model change. No new file, so nothing joins the vocabulary
list.

## 9. Testing

**New PHP — `Schedule` (unit, no database):**
- `onOrAfter()` returns a future candidate untouched; walks a past one forward
  along `daily`, `weekdays` and `weekly`; returns a past candidate untouched
  when the recurrence is null.
- `anchorAt()` with a null time returns null.
- `anchorAt()` with no date returns exactly what `firstOccurrence()` returns —
  asserted against `firstOccurrence()` itself, so the delegation cannot drift.
- `anchorAt()` with a future date and time returns that instant in UTC.
- `anchorAt()` with a past date on a weekly recurrence returns the next same
  weekday at that time.
- `anchorAt()` with a weekend date on `weekdays` lands on a weekday.
- `anchorAt()` with a past date on `daily` converges on `firstOccurrence()`'s
  answer — the inertness claimed in §4.

**New PHP — `RescheduleAction`:**
- A future date re-anchors the series to it, and nothing materialises before it.
- A past date on a weekly action anchors to the next occurrence of that weekday.
- A rename that posts the action's own past anchor date changes nothing and
  purges nothing — asserted against an unlogged future occasion that must
  survive. This is §3 of the frozen spec, re-pinned under the new branch.
- Changing only the date to a later date on the same weekday **does** reschedule.
  Killing mutation: compare the submitted date string instead of the resolved
  occurrence, and this silently passes as a no-op.
- Changing only the time on an action whose anchor is in the past re-anchors
  forward and materialises no past occasion.
- `once` with a changed past date throws a `ValidationException` on `date`.
- `once` with its own unchanged past date is a no-op and throws nothing.
- Called with `$date` null, every existing behaviour is unchanged — the whole of
  `SeriesAnchorTest` continues to assert this.

**New PHP — the endpoint:**
- The owner posts a date and the anchor lands on it (`RescheduleActionWebTest`).
- `date` in a bad format is rejected.
- A future-dated action's loop screen lists the action with no occurrence, and
  `/catch-up` is empty — the "a future date is probably the point" check, made
  as an assertion rather than an assumption.

**New JS:**
- The date input is absent for `daily` and for `weekdays`, present for `weekly`
  and `once`.
- Switching the recurrence select from `weekly` to `daily` removes the date
  input from the form.
- The date input pre-fills from the action's own `date`.
- The date input carries no `min` attribute.
- `cadenceLabel()` renders "weekly from 23 Sep at 07:30" for a future
  `starts_at`, and is unchanged when `starts_at` is absent, past, or null.

**Verification:**

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline entering this spec is whatever spec A leaves: **1028 PHP tests / 6533
assertions** unchanged by A, JS above 496. Both counts rise here.
`npm run build` must run first or `PwaManifestTest` skips itself and drops ~470
assertions. `npm run lint` runs inside the worktree — from the repository root
eslint descends into `.claude/worktrees/` and silently `--fix`es tracked files.

Tests are SQLite and production is MySQL: bind values in any raw SQL, and give
every `ORDER BY` a tiebreaker.

## 10. Out of scope

- **The add-an-action form stays time-only.** A date there means `AuthoredAction`,
  which reaches loop creation, `StartExperiment` and the connector's authoring
  pipeline. Add an action, then edit it to set a start date. The asymmetry is
  deliberate and is recorded in `docs/NOTEBOOK.md` §4.
- **The API and the MCP connector cannot send a date.** They pass null and
  behave as they do today.
- **Fortnightly and monthly**, which are the third spec on this branch. Their
  date fields follow the same rule as `weekly`'s.
- **`IntentionResource`'s `active_action`** is not widened with `starts_at`.
- **Nothing on the dashboard, catch-up or session screens.**
