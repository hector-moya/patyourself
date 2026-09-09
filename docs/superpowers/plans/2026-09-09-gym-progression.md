# Gym — Batch 3: progression, and closing the module's debt

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the per-exercise history screen the module was always going to end at, and close the seven pieces of debt batches 1 and 2 deliberately left, so the gym module is finished rather than merely shipped.

**Architecture:** One read model (`ExerciseHistory`) over a single joined query, one thin controller, one Inertia page, and two links that make it reachable. Then seven independent repairs: three lying docblocks, a thrice-duplicated verdict form, an N+1, a missing in-flight guard, a screen that offers no way to record, a nondeterministic sort, and two hardcoded URLs.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3, React 19, Tailwind v4, Wayfinder, PHPUnit 12 (SQLite in-memory), Vitest, MySQL in production, Pint, ESLint 9.

**Spec:** `docs/superpowers/specs/2026-09-04-training-module-design.md` — the "Progression" section.
**Governing architecture:** `docs/superpowers/specs/2026-09-05-workflow-architecture-design.md`
**Batch 1 (merged):** `docs/superpowers/plans/2026-09-06-gym-spine.md`
**Batch 2 (merged):** `docs/superpowers/plans/2026-09-07-gym-recording.md`
**The economy this must not break:** `docs/BLOB.md`

---

## Global Constraints

Every task's requirements implicitly include this section.

- **ONE OCCASION PRODUCES EXACTLY ONE `ActionLog`.** Recording does not log. Materialising does not log. `logCount` and `insightCount` are the whole economy. This batch reads; it writes no logs at all.
- **A loop with `workflow: null` must behave EXACTLY as before.** `tests/Feature/Workflows/PlainLoopIsUnchangedTest.php` is the guard and the architecture's central claim. Run it **by name** at the end of every task that touches shared code (tasks 6, 7), not absorbed into a green total.
- **This batch adds NO tables and NO columns.** No migrations. `Strategy`, `Action`, `Occurrence`, `ActionLog`, `Intention` gain nothing.
- **Record, never prescribe.** No chart. No PR detection, no estimated 1RM, no volume total, no strength score, no fraction of one, and **no trend described as progress**. Showing what you lifted is recording; telling you what it means is coaching.
- **Weight is kilograms, decimal. Body weight is NULL, never zero.** Render blank, never `0`. Null is "not applicable"; zero is a weight and a reader cannot tell them apart.
- **Banned vocabulary** — `CompanionVocabularyTest` scans listed files *including comments*: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. **`points` and `percent` are substring traps** — "endpoints" and "percentage" both trip them. Write "extension site" and "fraction".
- **All DB writes live in `app/Actions/*`** — a 0-exception convention. Controllers thin; FormRequests validate and authorise. Models use `#[Fillable]`, never `$guarded`.
- **PHPUnit, not Pest.** `php artisan test --compact --filter=Name`. **Never `vendor/bin/pint --test`** — use `vendor/bin/pint --dirty --format agent`.
- **Wayfinder** — import route helpers from `@/actions/` or `@/routes/`, never hardcode a URL. `php artisan wayfinder:generate` **MUST** take `--with-form`; a bare run regenerates the gitignored helpers without the Vite plugin's form variants and silently breaks ~53 unrelated JS tests, invisibly to `git status`.
- Feature tests that render an Inertia view need `$this->withoutVite()`.
- `App\Services\Training\` classes are plain `class`, matching `LastPerformance` and `SessionScreen` — **not** `final readonly`. `App\Services\Workflows\` uses `final readonly`; do not cross the two conventions.

### Baseline — measured in this worktree at `3e1df63`, after `npm run build`

| Check | Baseline | Command |
| --- | --- | --- |
| PHP tests | **981 passed**, 6125 assertions | `php artisan test --compact` |
| JS tests | **419 passed** (39 files) | `npx vitest run` |
| TypeScript | **exactly 1 error**, `resources/js/pages/catch-up.tsx(149,33)` | `npx tsc --noEmit` |
| Pint | clean | `vendor/bin/pint --dirty --format agent` |
| ESLint | clean | `npm run lint` |

Run `npm run build` **before** the PHP suite or `PwaManifestTest` skips and assertions drop to 6017. If `tsc` reports 3 errors with two of them `Cannot find module 'three'`, `node_modules` is stale — run `npm ci`. That is not a regression.

**The one tsc error will be removed by Task 6, deliberately.** It is `className` passed to `Button` in catch-up's copy of the verdict form — the single line on which the three duplicates diverge. Extracting the form converges them on the dashboard's `<div className="self-start">` wrapper, and the error goes with it. The constraint was "must not grow"; going to **0** satisfies it. Every task after 6 asserts **0** errors, and any task before 6 asserts exactly 1 at `catch-up.tsx(149,33)`.

### The verification list — run ALL FIVE

```bash
npm run build && php artisan test --compact && npx vitest run && npx tsc --noEmit \
  && vendor/bin/pint --dirty --format agent && npm run lint
```

`npm run lint` is on the list because its absence let three lint errors ride into main across two merges. **CI runs `eslint . --fix`, so auto-fixable problems never appear in its output — a green local `--fix` run is NOT evidence the committed tree is clean.** Commit whatever `--fix` changes.

### Traps that have each cost a round on this project

1. **A TEST WRITTEN AGAINST A FIXTURE'S DEFAULT ASSERTS NOTHING.** Name the killing mutation for every test and **actually run it**. Method that works: freeze the file, hash it, run mutations against the frozen copy, build the report by concatenating raw captures. **Re-capture after any later edit** — one table went stale by a single assertion that way, and one full-suite reading was captured mid-edit and looked like a flaky test for two review rounds.
2. **NEVER ATTRIBUTE A QUOTATION TO A DOCUMENT WITHOUT OPENING IT.** Four implementers have invented one on this project. Every time the code was fine and the reasoning defensible, and the invented permission was decoration. **An uncited judgment call, stated as your own, is always acceptable.**
3. **COLUMN-LIMITED EAGER LOADS hide new columns.** `->with('rel:id,name')` returns null for anything unnamed, forever, suite green. Bitten three times. Grep for `:id,`.
4. **A relation read inside a loop is an N+1.** Copy the query-count guard in `tests/Feature/IntentionScreensTest.php` or `TodaysOccasionsTest`.
5. **`ActionFactory` is NONDETERMINISTIC** — `recurrence` is `randomElement([null,'daily','weekdays'])` and `series_started_at` is random −3..+4 days. **Pin both.** An `anchored()` state nulls both.
6. **EVERY BATCH-1 GYM TEST USED `anchored()`**, which is exactly why an occasion-splitting defect survived six reviews. **COVER THE CLOCK-SCHEDULED PATH.**
7. **Pint's `fully_qualified_strict_types` rewrites an inline `::class` into a `use` import**, and once turned a docblock `@see` of a NAMESPACE into an invalid import. **Check imports after Pint.**
8. **`assertDatabaseMissing` on a column that does not exist is a CONSTANT-FALSE PREDICATE** — SQLite degrades it to a string literal and it passes forever; MySQL errors. Tests are SQLite, production is MySQL.
9. **`ActionExerciseFactory` / `PerformedSetFactory` sequence their unique columns in `configure()`**, not `definition()` — `->count(3)` numbers itself, a bare `->create()` still lands on 1.

### One correction to carry

The batch-2 reports frame `onOccurrenceMaterialised` as the mechanism closing the "verdict lands on the sets' occasion" constraint. **IT IS NOT.** `GymRecord` always navigates away, so the callback fires against an unmounting row. What actually closes it is the redirect into the session screen plus the `TodaysOccasions` dedupe. **Do not assume the seam carries weight** — in particular, Task 6 must not treat `onOccurrenceMaterialised` as load-bearing while moving the form around it, and must not "improve" it.

---

## The design calls this plan makes

Three, all mine, none quoted from any document. Each is flagged in the final report so the owner can overrule.

**1. The progression screen shows no target sets/reps.** The spec permits them ("may be shown, because the user wrote them"), and the ClickUp ticket states it more strongly. I am leaving them off. `ActionExercise` holds the *current* routine, and an exercise can sit in several actions' routines with different targets; attaching today's target to a session from three weeks ago misdates it, and the spec's own reason for showing a target — *you wrote it* — does not hold for a target written after the session it would sit beside. The layout in the ticket has no column for one either. If the owner wants them, the honest version is a single current-routine line in the header, not a per-row column, and it needs an answer for the several-routines case first.

**2. Mixed-weight sessions render per set.** The exercise screen's `formatLastPerformance` reads the weight off the first set only, documented there as "v1 does not attempt to summarise a session that mixed weights". On a history screen that is not a simplification, it is a false statement: a session of 60/60/65 would render as `60kg · 10 / 10 / 8`. So: sets that all share one weight collapse to `60kg · 10 / 10 / 8`; sets that do not render as `60kg × 10 / 60kg × 10 / 65kg × 8`. A null weight contributes bare reps in either form — never `0kg`.

**3. The history is not capped.** One exercise, one user, one query. A cap would hide record from the person who wrote it, and a silent one would read as "that is all there is". If it ever gets long enough to notice, paginate — that needs no schema change. Recorded here so it is a decision rather than an oversight.

---

## File Structure

**Created — server**

| File | Responsibility |
| --- | --- |
| `app/Services/Training/ExerciseHistory.php` | Read model: every session that recorded this exercise, newest first, in one query. |
| `app/Http/Controllers/Training/ProgressionController.php` | The progression page. Read-only; authorises the exercise is in the user's catalogue. |
| `tests/Feature/Training/ExerciseHistoryTest.php` | The read model's guards. |
| `tests/Feature/Training/ProgressionScreenTest.php` | Route, authorisation, payload, render. |

**Created — client**

| File | Responsibility |
| --- | --- |
| `resources/js/pages/training/progression.tsx` | The screen. |
| `resources/js/pages/training/progression.test.tsx` | Its guards, including the vocabulary it must not use. |
| `resources/js/patyourself/verdict-form.tsx` | The outcome radios + reason + submit, extracted from three copies. |
| `resources/js/patyourself/verdict-form.test.tsx` | Its guards. |

**Modified**

| File | Change |
| --- | --- |
| `routes/web.php` | The progression route. |
| `resources/js/pages/training/exercise.tsx` | Link to progression; fix the missing open row. |
| `resources/js/patyourself/training/routine-editor.tsx` | Link to progression; in-flight guard on `move()`. |
| `resources/js/pages/dashboard.tsx` | Use the extracted form; Wayfinder helpers. |
| `resources/js/pages/catch-up.tsx` | Same. |
| `resources/js/pages/training/session.tsx` | Same (no endpoint change — it already uses a helper). |
| `app/Models/Action.php` | `upcomingOccurrences()`, and `nextOccurrenceAt()` honouring it. |
| `app/Http/Controllers/IntentionController.php` | Eager-load it, killing the N+1. |
| `app/Services/Training/LastPerformance.php` | Sort tiebreaker. |
| `tests/Feature/Workflows/WorkflowColumnTest.php` | Correct or confirm the mutation claim at :114. |
| `resources/js/pages/loops/show.test.tsx` | Same, at :700. |
| `app/Notifications/DailyDigestNotification.php` | Repair the ungrammatical comment at :53. |
| `tests/Feature/Companion/CompanionVocabularyTest.php` | The new source files. |

---

## Task sequencing

Tasks 1–4 are Part A and build on each other. Tasks 5–9 are Part B and are **mutually independent** — a reviewer can reject any one without touching its neighbours. Task 10 is the sweep, task 11 the merge.

---

### Task 1: `ExerciseHistory` — the read model

**Files:**
- Create: `app/Services/Training/ExerciseHistory.php`
- Test: `tests/Feature/Training/ExerciseHistoryTest.php`

**Interfaces:**
- Consumes: `App\Models\Exercise`, `App\Models\PerformedSet`, `App\Models\User`.
- Produces:

```php
public function forExercise(Exercise $exercise, User $user): array
```

returning

```php
/**
 * @return list<array{
 *     occurrence_id: int,
 *     performed_at: CarbonImmutable,
 *     sets: list<array{reps: int, weight: float|null}>,
 * }>
 */
```

newest session first, sets within a session in `set_number` order. `performed_at` is `occurrences.scheduled_for`, in UTC — the controller applies the user's timezone, exactly as `ExerciseController` already does with `LastPerformance`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Training/ExerciseHistoryTest.php`. Read `tests/Feature/Training/LastPerformanceTest.php` first and copy its fixture helpers rather than inventing new ones.

```php
public function test_it_returns_one_entry_per_occasion_newest_first(): void
public function test_sets_within_a_session_are_in_set_number_order(): void
public function test_a_body_weight_set_comes_back_null_not_zero(): void
public function test_another_users_sets_on_the_same_catalogue_exercise_are_excluded(): void
public function test_history_survives_the_exercise_leaving_every_routine(): void
public function test_history_survives_a_strategy_revision_that_dropped_the_exercise(): void
public function test_an_exercise_with_no_history_returns_an_empty_list(): void
public function test_it_costs_the_same_number_of_queries_for_ten_sessions_as_for_one(): void
public function test_two_occasions_sharing_a_scheduled_for_are_ordered_deterministically(): void
```

**Pin the factory** (trap 5): every `Action::factory()` here must set `recurrence` and `series_started_at` explicitly. **Cover the clock-scheduled path** (trap 6) — at least the first, fourth and eighth tests must use a clock-scheduled action, not `anchored()`.

**Named killing mutations — run every one:**

| Test | Mutation | Expected |
| --- | --- | --- |
| newest first | `orderByDesc` → `orderBy` on `scheduled_for` | order reverses, red |
| set order | drop `orderBy('performed_sets.set_number')` and insert sets out of order | red |
| body weight null | `(float) $set->weight` without the null check | `0.0` where `null` expected, red |
| another user's sets | drop `->where('intentions.user_id', ...)` | the stranger's session appears, red |
| leaving every routine | join through `action_exercises` | empty list, red |
| strategy revision | same as above | empty list, red |
| no history | return a one-element list unconditionally | red |
| query count | replace the join with a per-occurrence read in a loop | count grows with sessions, red |
| tiebreaker | drop `orderByDesc('occurrences.id')` | non-deterministic; assert the specific expected order and show it fail with the tiebreaker removed **on the same seeded data** |

For the query-count test copy the shape from `tests/Feature/IntentionScreensTest.php::test_the_experiment_ladder_costs_the_same_however_many_versions_it_has` — do not invent a new counting harness.

For `test_history_survives_the_exercise_leaving_every_routine`: create the routine row, record sets against an occasion, then delete the `ActionExercise` row (**not** the `Exercise` — `exercise_id` restricts, so an exercise carrying sets cannot be deleted at all; that is the schema working, and it is why this test removes the routine row instead). Assert the sets still come back.

For `test_history_survives_a_strategy_revision_that_dropped_the_exercise`: copy the revision payload from `tests/Feature/Experiments/StartExperimentWebTest.php` rather than inventing one, and assert the version actually advanced — a revision that silently no-ops would otherwise pass.

- [ ] **Step 2: Run to verify they fail**

```bash
php artisan test --compact --filter=ExerciseHistoryTest
```

Expected: `Class "App\Services\Training\ExerciseHistory" not found`.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Training;

use App\Models\Exercise;
use App\Models\PerformedSet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The progression screen's read model: every occasion that recorded this
 * exercise, newest first, with the sets it carried.
 *
 * A pure read, and deliberately a thin one — no record detection, no 1RM, no
 * volume total, no fraction of one, no trend called progress. This is the
 * screen where the temptation is strongest, so the line is drawn hardest
 * here: showing what was lifted is recording; saying what it means is
 * coaching.
 *
 * One query, whatever the history's length. The occasion is reached by
 * joining rather than by reading `$set->occurrence` per row, which is the
 * N+1 this module is likeliest to grow — the sibling read
 * {@see LastPerformance} records why the earlier `pluck`-and-feed-back shape
 * was worse still.
 *
 * Scoped to the user through `intentions.user_id`, because the catalogue is
 * shared: a bare read by exercise id would hand one person's training to
 * another.
 *
 * The sort carries a tiebreaker on `occurrences.id`. Two occasions can share
 * a `scheduled_for` — different actions, same slot — and without it their
 * order is whatever the engine happens to return, which differs between
 * SQLite here and MySQL in production.
 *
 * Nothing filters on whether the occasion was logged. What was lifted is what
 * was lifted; the verdict is a separate fact about the occasion and does not
 * decide whether the sets happened.
 */
class ExerciseHistory
{
    /**
     * @return list<array{
     *     occurrence_id: int,
     *     performed_at: CarbonImmutable,
     *     sets: list<array{reps: int, weight: float|null}>,
     * }>
     */
    public function forExercise(Exercise $exercise, User $user): array
    {
        $rows = PerformedSet::query()
            ->select([
                'performed_sets.occurrence_id',
                'performed_sets.set_number',
                'performed_sets.reps',
                'performed_sets.weight',
                'occurrences.scheduled_for as occasion_scheduled_for',
            ])
            ->join('occurrences', 'occurrences.id', '=', 'performed_sets.occurrence_id')
            ->join('actions', 'actions.id', '=', 'occurrences.action_id')
            ->join('intentions', 'intentions.id', '=', 'actions.intention_id')
            ->where('performed_sets.exercise_id', $exercise->id)
            ->where('intentions.user_id', $user->id)
            ->orderByDesc('occurrences.scheduled_for')
            ->orderByDesc('occurrences.id')
            ->orderBy('performed_sets.set_number')
            ->get();

        return $rows
            ->groupBy('occurrence_id')
            ->map(fn (Collection $sets, int|string $occurrenceId): array => [
                'occurrence_id' => (int) $occurrenceId,
                'performed_at' => CarbonImmutable::parse(
                    $sets->first()->getAttribute('occasion_scheduled_for'),
                ),
                'sets' => $sets->map(fn (PerformedSet $set): array => [
                    'reps' => $set->reps,
                    'weight' => $set->weight === null ? null : (float) $set->weight,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
```

`Collection::groupBy` preserves the order keys are first encountered, so the newest-first ordering survives the grouping — that is why the sort lives in SQL and the grouping in PHP, and why the query cannot be reordered without breaking the screen.

- [ ] **Step 4: Prove every mutation in the table above**

Freeze the file and hash it first (`shasum app/Services/Training/ExerciseHistory.php`), run each mutation against the frozen copy, and build the report by concatenating raw captures. A failure message's shape must match the assertion it is attributed to.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact --filter=ExerciseHistoryTest
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat(gym): read an exercise's whole history in one query"
```

---

### Task 2: The progression route and controller

**Files:**
- Create: `app/Http/Controllers/Training/ProgressionController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Training/ProgressionScreenTest.php`

**Interfaces:**
- Consumes: `ExerciseHistory::forExercise(Exercise, User): array` (Task 1).
- Produces: `GET /exercises/{exercise}/progression` → `training.progression.show`, rendering `training/progression` with:

```
exercise: { id: number, name: string }
sessions: [ { occurrence_id: number, performed_at: string, sets: [{ reps: number, weight: number|null }] } ]
```

`performed_at` is an ISO-8601 string already in the user's timezone.

- [ ] **Step 1: Write the failing tests**

```php
public function test_it_renders_the_exercises_history_newest_first(): void
public function test_a_guest_is_redirected_to_login(): void
public function test_another_users_private_exercise_is_a_404(): void
public function test_a_shared_catalogue_exercise_is_reachable(): void
public function test_it_renders_with_no_history_at_all(): void
public function test_the_dates_are_in_the_users_own_timezone(): void
```

Every test that renders needs `$this->withoutVite()`.

`test_another_users_private_exercise_is_a_404` is the IDOR guard — batch 2's review found exactly this on `ExerciseController`. Create an `Exercise` owned by another user, request it as this user, assert **404, not 403**: an exercise outside your catalogue is one that does not exist as far as you are concerned, and 403 would confirm the id is real.

**Named killing mutations:**

| Test | Mutation | Expected |
| --- | --- | --- |
| newest first | reverse the read model's sort | red on order |
| guest | drop the `auth` middleware group | 200, red |
| another user's exercise | drop the `availableTo` check | 200, red |
| shared exercise | change `availableTo` to `where('user_id', $user->id)` | 404, red |
| no history | return early with a 404 when `sessions` is empty | red |
| timezone | drop `->timezone($timezone)` | the assertion on the rendered offset fails, red |

For the timezone test, set the user's `timezone` to something with a non-zero offset (e.g. `Pacific/Auckland`) and assert the emitted string carries that offset — asserting only that a string exists proves nothing.

- [ ] **Step 2: Run to verify they fail.** Expected: 404, route not defined.

- [ ] **Step 3: Implement the controller**

```php
<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Services\Training\ExerciseHistory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The progression screen: what was lifted on this exercise, for how many
 * reps, on what date — newest first.
 *
 * Keyed on the exercise alone, not on an occasion: this is the one screen in
 * the module that is about a movement across sessions rather than about one
 * session, and it is reachable outside a session for that reason.
 *
 * The catalogue is shared, so owning an account says nothing about owning an
 * exercise. Scoped by {@see Exercise::availableTo()}, the same rule the
 * routine and set-writing requests already apply, and refused as a 404 rather
 * than a 403 for the same reason they are: an exercise outside your catalogue
 * is one that does not exist as far as you are concerned, and a 403 would
 * confirm the id is real. The history itself is scoped again inside
 * {@see ExerciseHistory}, by the owning loop — the two are different
 * questions and both have to be asked.
 *
 * Record, never prescribe. There is no chart, no record detection, no 1RM, no
 * volume total, no fraction of one, and no trend named. The screen states what
 * happened and stops.
 */
class ProgressionController extends Controller
{
    public function show(Exercise $exercise, Request $request, ExerciseHistory $history): Response
    {
        abort_unless(
            Exercise::query()->availableTo($request->user())->whereKey($exercise->id)->exists(),
            404,
        );

        $timezone = $request->user()->timezone ?? (string) config('app.timezone');

        return Inertia::render('training/progression', [
            'exercise' => [
                'id' => $exercise->id,
                'name' => $exercise->name,
            ],
            'sessions' => collect($history->forExercise($exercise, $request->user()))
                ->map(fn (array $session): array => [
                    'occurrence_id' => $session['occurrence_id'],
                    'performed_at' => $session['performed_at']->timezone($timezone)->toIso8601String(),
                    'sets' => $session['sets'],
                ])
                ->all(),
        ]);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the existing `auth` group, immediately after the `training.exercises.index` block:

```php
    // What was lifted on one exercise, across every session that recorded it.
    // Keyed on the exercise alone — the only training screen that is about a
    // movement rather than about one occasion, and reachable outside a
    // session for that reason. Unthrottled, unlike the catalogue search above:
    // this resolves one bound model rather than scanning the catalogue.
    Route::get('exercises/{exercise}/progression', [ProgressionController::class, 'show'])
        ->name('training.progression.show');
```

Import `ProgressionController` alongside its siblings at the top of the file.

- [ ] **Step 5: Regenerate Wayfinder — `--with-form` is not optional**

```bash
php artisan wayfinder:generate --with-form
```

- [ ] **Step 6: Prove the mutations, verify, commit**

```bash
php artisan test --compact --filter=ProgressionScreenTest
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Check imports after Pint (trap 7): `fully_qualified_strict_types` has previously turned a docblock `@see` of a namespace into an invalid import.

```bash
git add -A && git commit -m "feat(gym): the progression route and its read"
```

---

### Task 3: The progression screen

**Files:**
- Create: `resources/js/pages/training/progression.tsx`, `resources/js/pages/training/progression.test.tsx`

**Interfaces:**
- Consumes: the payload from Task 2.
- Produces: default-exported `ProgressionScreen`, plus the named export `formatSession` for its own test.

The layout, from the spec:

```
Bench press
  60kg · 10 / 10 / 8     2 Sep
  60kg · 10 / 9  / 8    29 Aug
  57.5kg · 10 / 10 / 10 26 Aug
```

- [ ] **Step 1: Write the failing tests**

`resources/js/pages/training/progression.test.tsx`. Read `resources/js/pages/training/exercise.test.tsx` first and match its harness — same render helper, same query style.

```
renders one row per session, newest first
renders a uniform-weight session as "60kg · 10 / 10 / 8"
renders a mixed-weight session per set, never collapsing to the first weight
renders a body-weight session as reps only — never "0kg"
renders a mixed session containing a body-weight set without printing "0kg"
renders an empty state when there is no history, and no chart, table or axis
renders no back control — the screen has two entry points and cannot know which was used
says nothing prescriptive: no "record", "best", "1RM", "progress", "percentage", "target"
```

The last one asserts against the **rendered output**, not the source — a vocabulary guard on the source is Task 10's job and a different guard. Assert the absence of each term case-insensitively in `container.textContent`.

**Named killing mutations:**

| Test | Mutation | Expected |
| --- | --- | --- |
| newest first | `.slice().reverse()` the sessions before mapping | red on order |
| uniform weight | always use the mixed-weight branch | `60kg × 10 / …`, red |
| mixed weight | always use the uniform branch (first set's weight) | `60kg · 10 / 10 / 8` for a 60/60/65 session, red |
| body weight | `${weight ?? 0}kg` | `0kg` appears, red |
| mixed + body weight | same | `0kg` appears, red |
| empty state | render the list unconditionally | the empty copy is absent, red |
| no back control | pass a `headerLeading` link to `CoachLayout` | an anchor appears in the header, red |
| vocabulary | add the word `progress` to the heading | red |

- [ ] **Step 2: Run to verify they fail**

```bash
npx vitest run resources/js/pages/training/progression.test.tsx
```

Expected: cannot resolve `./progression`.

- [ ] **Step 3: Implement**

```tsx
import CoachLayout from '@/layouts/coach-layout';
import { formatOccasionDay } from '@/patyourself/occasion-date';

export interface ProgressionSet {
    reps: number;
    /** Kilograms, or null for body weight — never zero. Zero is a weight;
     *  null is "not applicable", and the two must render differently. */
    weight: number | null;
}

export interface ProgressionSession {
    occurrence_id: number;
    /** ISO timestamp, already in the user's own timezone. */
    performed_at: string;
    sets: ProgressionSet[];
}

export interface ProgressionProps {
    exercise: { id: number; name: string };
    /** Newest first. Empty, never absent, when nothing has been recorded. */
    sessions: ProgressionSession[];
}

/**
 * What was lifted on one exercise, newest first.
 *
 * Record, never prescribe — and this is the screen where that is hardest to
 * hold, so it is stated hardest here. There is no chart: there is nothing to
 * chart until there is history, and a sparkline over three sessions is
 * decoration. There is no record detection, no estimated 1RM, no volume
 * total, no fraction of one, and no trend named. Every number on this screen
 * is one the user recorded, shown back to them unchanged.
 *
 * This screen carries no back control. `CoachLayout`'s `headerLeading` is
 * optional, and there are two ways in — the exercise screen mid-session, and
 * a routine row on the loop's own record — with nothing in the payload saying
 * which. A fixed back link would send half its visitors somewhere they did
 * not come from, and there is no `GET /exercises/{exercise}` route for one to
 * point at in any case.
 */
export default function ProgressionScreen({
    exercise,
    sessions,
}: ProgressionProps) {
    return (
        <CoachLayout title={exercise.name}>
            <div className="flex flex-col gap-6">
                <p className="text-sm text-foreground">{exercise.name}</p>

                {sessions.length === 0 ? (
                    <p
                        data-testid="progression-empty"
                        className="text-sm text-muted-foreground"
                    >
                        Nothing recorded on this one yet.
                    </p>
                ) : (
                    <ol
                        data-testid="progression-list"
                        className="flex flex-col divide-y divide-border"
                    >
                        {sessions.map((session) => (
                            <li
                                key={session.occurrence_id}
                                data-testid={`progression-row-${session.occurrence_id}`}
                                className="flex items-baseline justify-between gap-3 py-2"
                            >
                                <span className="min-w-0 font-mono text-sm text-foreground">
                                    {formatSession(session.sets)}
                                </span>
                                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                    {formatOccasionDay(session.performed_at, {
                                        day: 'numeric',
                                        month: 'short',
                                    })}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </CoachLayout>
    );
}

/**
 * "60kg · 10 / 10 / 8" when every set carried the same weight, and
 * "60kg × 10 / 60kg × 10 / 65kg × 8" when they did not.
 *
 * The exercise screen's own last-session line reads the weight off the first
 * set and says so, which is a fair simplification for one line about one
 * session. Across a history it would be a false statement: a session of
 * 60/60/65 is not a session at 60. So a mixed session is spelled out rather
 * than collapsed.
 *
 * A null weight is body weight — "not applicable" — and contributes bare reps
 * in either form. It is never rendered as `0kg`: zero is a weight, and a
 * reader cannot tell the two apart.
 */
export function formatSession(sets: ProgressionSet[]): string {
    if (sets.length === 0) {
        return '';
    }

    const distinctWeights = new Set(sets.map((set) => set.weight));

    if (distinctWeights.size === 1) {
        const weight = sets[0].weight;
        const reps = sets.map((set) => set.reps).join(' / ');

        return weight === null ? reps : `${weight}kg · ${reps}`;
    }

    return sets
        .map((set) =>
            set.weight === null ? `${set.reps}` : `${set.weight}kg × ${set.reps}`,
        )
        .join(' / ');
}
```

- [ ] **Step 4: Prove the mutations, then verify**

```bash
npx vitest run
npx tsc --noEmit    # exactly 1 error at catch-up.tsx(149,33) — Task 6 removes it
npm run lint
```

State the new JS test total rather than assuming it.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(gym): the progression screen"
```

---

### Task 4: Making it reachable

A screen nobody can open is not shipped. Two doors: from the exercise screen mid-session, and from the routine row on the loop's own record, which is the one that works when no session is running.

**Files:**
- Modify: `resources/js/pages/training/exercise.tsx`
- Modify: `resources/js/patyourself/training/routine-editor.tsx`
- Test: `resources/js/pages/training/exercise.test.tsx` (extend), `resources/js/patyourself/training/routine-editor.test.tsx` (extend)

**Interfaces:**
- Consumes: the Wayfinder helper generated in Task 2 — `import { show as showProgression } from '@/routes/training/progression';`

Confirm that import path resolves before writing against it:

```bash
ls resources/js/routes/training/
```

If the generated module differs, use whatever `wayfinder:generate --with-form` actually produced. **Do not hardcode the URL.**

- [ ] **Step 1: Write the failing tests**

In `exercise.test.tsx`: the screen renders a link whose href is the progression URL for this exercise, labelled so it names history rather than a judgement — "Earlier sessions".

In `routine-editor.test.tsx`: a routine row whose `exercise_name` is present renders its name as a link to that exercise's progression; a row whose `exercise_name` is null (no longer in the catalogue) renders plain text and **no link**.

Assert both hrefs against the helper's own `.url()` output, never against a hand-written string — a test that hardcodes the URL cannot see the helper drift away from the route.

**Named killing mutations:** drop the link from `exercise.tsx` — the exercise test goes red. Link the routine row unconditionally — the null-name test finds an anchor and goes red.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement**

In `exercise.tsx`, beneath the `last` line:

```tsx
<Link
    href={showProgression.url(exercise.id)}
    className="text-right text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
>
    Earlier sessions
</Link>
```

In `routine-editor.tsx`, the row name becomes a link when there is a name to link:

```tsx
{row.exercise_name === null ? (
    <span
        data-testid={`routine-row-name-${row.id}`}
        className="min-w-0 flex-1 truncate text-sm text-foreground"
    >
        This exercise is no longer in the catalogue
    </span>
) : (
    <Link
        href={showProgression.url(row.exercise_id)}
        data-testid={`routine-row-name-${row.id}`}
        className="min-w-0 flex-1 truncate text-sm text-foreground underline underline-offset-2 hover:text-muted-foreground"
    >
        {row.exercise_name}
    </Link>
)}
```

The `data-testid` stays on whichever element renders, so existing assertions keep working. A row with no catalogue name gets no link because there is no exercise left to show a history for — the name is null precisely because the row's exercise is unresolvable.

- [ ] **Step 4: Verify**

```bash
npx vitest run
npx tsc --noEmit    # exactly 1, unchanged
npm run lint
```

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(gym): two doors into the progression screen"
```

---

### Task 5: Three docblocks that claim a mutation — verify before rewriting

**Files:**
- Modify: `tests/Feature/Workflows/WorkflowColumnTest.php:105-118`
- Modify: `resources/js/pages/loops/show.test.tsx:693-702`
- Modify: `app/Notifications/DailyDigestNotification.php:51-57`

**The guards work. The prose is what is in question.** Do not change any assertion to make a claim true — if a claim is false, correct the claim.

- [ ] **Step 1: Run the mutation `WorkflowColumnTest:114` claims**

The claim: *"remove ConvertEmptyStringsToNull from bootstrap/app.php's middleware stack. `''` then reaches the `Rule::in` and this test fails on `assertSessionHasNoErrors`."*

Actually do it. In `bootstrap/app.php`:

```php
$middleware->remove(\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);
```

then

```bash
php artisan test --compact --filter=test_the_pickers_empty_choice_returns_a_loop_to_no_workflow
```

Capture the raw output. Revert `bootstrap/app.php` immediately afterwards and confirm with `git diff --stat`.

**Check the failure's shape matches the claim.** The claim says it fails on `assertSessionHasNoErrors`. If it instead fails on the `assertDatabaseHas`, or passes outright, the docblock is wrong and the rewrite must say what actually happens. Read `app/Http/Requests/UpdateIntentionRequest.php`'s `workflow` rule before concluding — whether `''` reaches `Rule::in` at all depends on whether the rule set makes it implicit.

- [ ] **Step 2: Run the mutation `show.test.tsx:700` claims**

The claim: *"render the editor whenever `routine` is present, ignoring the loop's workflow."*

Find the actual condition in `resources/js/pages/loops/show.tsx`, apply that mutation, and run:

```bash
npx vitest run resources/js/pages/loops/show.test.tsx
```

Capture raw output; revert. **The claim has a second half — "It would appear on a plain loop whose server props happened to carry a routine."** Check whether the test's own fixture actually supplies a routine on the plain loop. If it does not, the mutation cannot fire and the test is a fixture-default test (trap 1) — in which case fix the *fixture* so the mutation does fire, and say so.

- [ ] **Step 3: Repair the comment at `DailyDigestNotification:53`**

It currently reads:

> logging is one thing that creates the occasion, and since the gym module beginning to record is another, so a cue-anchored row mid-session does carry links here.

That sentence has lost a clause. The meaning it is reaching for — verify it against `MaterialisesOccasion` before writing, do not take this plan's word for it — is that there are now two ways an occasion comes into being, logging and beginning to record, so a cue-anchored row that is mid-session does have an occurrence and therefore does carry one-click links. Rewrite it as grammatical prose saying exactly that, no more.

This one makes no mutation claim, so nothing to run — but confirm the behaviour it describes is real: a cue-anchored action whose occasion has been materialised but not logged must appear in the digest **with** links. If no test covers that, add one to the digest's existing test file and name its killing mutation.

- [ ] **Step 4: Rewrite the two docblocks to match what you measured**

If a claim held, say so and leave it — but add nothing that was not run. If it did not, write what does kill the test. **An uncited judgment call stated as your own is always acceptable; an invented attribution is not** (trap 2).

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --compact && npx vitest run && npm run lint
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "docs(tests): make three mutation claims match what the mutations do"
```

Your report must include the **raw captured output** of both mutation runs, and must state plainly which claims were true and which were not.

---

### Task 6: Extract the verdict form — third occurrence

**Files:**
- Create: `resources/js/patyourself/verdict-form.tsx`, `resources/js/patyourself/verdict-form.test.tsx`
- Modify: `resources/js/pages/dashboard.tsx:234-290`, `resources/js/pages/catch-up.tsx:97-156`, `resources/js/pages/training/session.tsx:186-252`

The outcome radios, the conditional reason textarea, and the conditional submit are byte-for-byte identical in three files. They diverge on exactly three things: the endpoint, an optional `data-testid`, and the `Form`'s own className — plus one accident, catch-up passing `className` straight to `Button` where the other two wrap it in a `div`. That accident **is** the repository's single TypeScript error, and converging on the dashboard's shape removes it.

- [ ] **Step 1: Write the failing tests**

`resources/js/patyourself/verdict-form.test.tsx`:

```
posts to the action it is given
shows no reason field until "Did not hold" is chosen
shows the reason field once "Did not hold" is chosen
hides the reason field again when the outcome changes away from "Did not hold"
shows no submit until an outcome is chosen
renders the server's reason error when there is one
passes its testId through to the form element
```

**Named killing mutations:** render the textarea unconditionally — test 2 red. Render it for `completed` too — test 4 red. Render the submit unconditionally — test 5 red. Drop the `errors.reason` branch — test 6 red. Hardcode the action — test 1 red.

The three existing test files (`dashboard.test.tsx`, `catch-up.test.tsx`, `session.test.tsx`) are the real regression guard here and **must not be weakened to accommodate the refactor**. If an existing assertion breaks, the extraction is wrong, not the assertion. Run all three by name before and after.

- [ ] **Step 2: Run to verify they fail.** Expected: cannot resolve `./verdict-form`.

- [ ] **Step 3: Implement**

```tsx
import { Form } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/patyourself/primitives';
import type { LogOutcome } from '@/patyourself/types';

export interface VerdictFormProps {
    /** Where the verdict posts. Every caller resolves this through a
     *  Wayfinder helper — the two endpoints differ in what they key on, and
     *  choosing between them is the caller's question, not this form's. */
    action: string;
    className?: string;
    testId?: string;
}

/**
 * The verdict controls: which outcome, the reason a failure carries, and the
 * press that records it.
 *
 * Extracted at the third identical copy — dashboard, catch-up and the gym
 * session screen — where it had already drifted once: catch-up passed
 * `className` to `Button`, which does not accept one, and that was the
 * repository's only TypeScript error. Three copies of a form is three places
 * to fix the next thing, and this one carries a rule that must not vary.
 *
 * `skipped` means the occasion never happened. `failed` means it happened and
 * the strategy did not hold — including simply not thinking about it. Neither
 * label says anything about the person.
 *
 * A failure carries the user's own words, the same rule the tool boundary
 * enforces, for the same reason: the reason is the evidence a revision is
 * argued from, and a verdict without one is an outcome nobody can learn from.
 *
 * Entering a workflow's record never decides the outcome — filling in three
 * sets and then marking the session missed because you cut it short is a real
 * thing that happens, and a form that inferred "done" from the presence of
 * data would be overruling the person who was there. Which is why this form
 * knows nothing about any workflow.
 */
export default function VerdictForm({
    action,
    className = 'mt-2 flex flex-col gap-2',
    testId,
}: VerdictFormProps) {
    const [outcome, setOutcome] = useState<LogOutcome | null>(null);

    return (
        <Form
            action={action}
            method="post"
            options={{ preserveScroll: true }}
            className={className}
            data-testid={testId}
        >
            {({ processing, errors }) => (
                <>
                    <div className="flex flex-wrap gap-2">
                        {OUTCOMES.map((option) => (
                            <label
                                key={option.value}
                                className="flex cursor-pointer items-center gap-1.5 rounded-full border border-border px-3 py-1 text-xs text-muted-foreground has-checked:border-primary has-checked:text-foreground"
                            >
                                <input
                                    type="radio"
                                    name="outcome"
                                    value={option.value}
                                    checked={outcome === option.value}
                                    onChange={() => setOutcome(option.value)}
                                    className="sr-only"
                                />
                                {option.label}
                            </label>
                        ))}
                    </div>

                    {outcome === 'failed' && (
                        <div className="flex flex-col gap-1">
                            <textarea
                                name="reason"
                                rows={2}
                                placeholder="What happened, in your words"
                                aria-label="What happened, in your words"
                                className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            />
                            {errors.reason && (
                                <p className="text-xs text-destructive">
                                    {errors.reason}
                                </p>
                            )}
                        </div>
                    )}

                    {outcome !== null && (
                        <div className="self-start">
                            <Button type="submit" disabled={processing}>
                                Log it
                            </Button>
                        </div>
                    )}
                </>
            )}
        </Form>
    );
}

const OUTCOMES: { value: LogOutcome; label: string }[] = [
    { value: 'completed', label: 'Did it' },
    { value: 'failed', label: 'Did not hold' },
    { value: 'skipped', label: 'Never happened' },
];
```

Check the real import path and export style of `Button` in each of the three call sites before writing this — copy whichever they already use rather than the shape above, if they differ.

- [ ] **Step 4: Replace the three copies, and use Wayfinder while you are in there**

`dashboard.tsx` — `logEndpoint` keeps its whole docblock (it is the reasoning that keeps the verdict on the sets' occasion) and stops building strings by hand:

```tsx
import { store as storeActionLog } from '@/routes/actions/logs';
import { store as storeOccurrenceLog } from '@/routes/occurrences/logs';

function logEndpoint(actionId: number, occurrenceId: number | null): string {
    return occurrenceId === null
        ? storeActionLog.url(actionId)
        : storeOccurrenceLog.url(occurrenceId);
}
```

Both helper modules export `store`, so **the imports must be aliased** — an unaliased pair is a redeclaration and will not compile.

Then the row body becomes:

```tsx
<VerdictForm
    action={logEndpoint(occasion.action_id, occurrenceId)}
    testId={`occasion-form-${occasion.action_id}`}
/>
```

`catch-up.tsx`:

```tsx
import { store as storeOccurrenceLog } from '@/routes/occurrences/logs';
...
<VerdictForm action={storeOccurrenceLog.url(occurrenceId)} />
```

`session.tsx` keeps its existing helper import and its own className:

```tsx
<VerdictForm
    action={storeLog.url(occurrenceId)}
    className="flex flex-col gap-2"
    testId="session-verdict-form"
/>
```

Delete the now-unused local `OUTCOMES` constants and `useState` imports from all three. **`catch-up.tsx`'s `OUTCOMES` carries a docblock explaining what `skipped` and `failed` mean** — that reasoning moves into `verdict-form.tsx` (it is already in the docblock above); do not simply delete it.

**Do not touch `onOccurrenceMaterialised` or the `occurrenceId` state.** They are not load-bearing for the verdict-lands-on-the-right-occasion constraint — the redirect into the session screen plus the `TodaysOccasions` dedupe is what closes that — but they are shipped behaviour with their own tests, and this task is an extraction, not a redesign.

- [ ] **Step 5: Verify**

```bash
npx vitest run resources/js/pages/dashboard.test.tsx
npx vitest run resources/js/pages/catch-up.test.tsx
npx vitest run resources/js/pages/training/session.test.tsx
npx vitest run
npx tsc --noEmit          # now 0 errors — state this explicitly
npm run lint
php artisan test --compact --filter=PlainLoopIsUnchangedTest
```

The last one is run by name deliberately: this task edits the dashboard and catch-up screens every loop in the app shares, and the plain-loop guard is the architecture's central claim.

- [ ] **Step 6: Commit**

```bash
git add -A && git commit -m "refactor: one verdict form, and route helpers instead of strings"
```

---

### Task 7: The N+1 behind `nextOccurrenceAt()`

**Files:**
- Modify: `app/Models/Action.php:102-116`
- Modify: `app/Http/Controllers/IntentionController.php:192-221`
- Test: `tests/Feature/IntentionScreensTest.php` (extend)

`IntentionController::actionLayer()` maps over a loop's actions calling `$action->nextOccurrenceAt()`, which issues its own query per action. Pre-existing, and `/loops/{id}` is where it bites.

- [ ] **Step 1: Write the failing test**

Extend `tests/Feature/IntentionScreensTest.php`, copying the counting shape from `test_the_experiment_ladder_costs_the_same_however_many_versions_it_has` — do not invent a new harness.

```php
public function test_the_action_layer_costs_the_same_for_five_actions_as_for_one(): void
```

Build a loop with one action, count queries rendering `/loops/{id}`; build another with five, count again; assert equal. **Pin `ActionFactory`** (trap 5) — set `recurrence` and `series_started_at` explicitly on every action, and give each a materialised future occurrence so `nextOccurrenceAt()` has something to find. Cover the **clock-scheduled** path (trap 6).

**Killing mutation:** remove the eager load from `actionLayer` — the five-action count exceeds the one-action count and the test goes red.

- [ ] **Step 2: Run to verify it fails.** Expected: counts differ by four.

- [ ] **Step 3: Implement**

In `app/Models/Action.php`, add the relation beside `occurrences()`:

```php
/**
 * The occasions still ahead of now and still awaiting an outcome, soonest
 * first. Exists so a screen listing several actions can load all of their
 * next occasions in one query instead of one per action — see
 * {@see self::nextOccurrenceAt()}.
 *
 * @return HasMany<Occurrence, $this>
 */
public function upcomingOccurrences(): HasMany
{
    return $this->occurrences()
        ->unlogged()
        ->where('scheduled_for', '>=', Date::now())
        ->orderBy('scheduled_for');
}
```

and make `nextOccurrenceAt()` honour it, keeping its existing docblock and extending rather than replacing it:

```php
public function nextOccurrenceAt(): ?CarbonImmutable
{
    // Honour an eager load when the caller arranged one. A screen listing
    // several actions loads `upcomingOccurrences` once for all of them; a
    // caller holding a single action pays for its own query, which is the
    // cheaper of the two for one row.
    if ($this->relationLoaded('upcomingOccurrences')) {
        return $this->upcomingOccurrences->first()?->scheduled_for;
    }

    return $this->upcomingOccurrences()->value('scheduled_for');
}
```

**Corrected during execution.** This block first restated the relation's three clauses inline, and review called that what it was: verbatim duplication of a logic block, which this project's rubric treats as Important. Calling `upcomingOccurrences()` **with parentheses** builds a fresh query builder without consulting or populating the loaded relation, so the fallback keeps its cheap, single-column, uncached semantics while the two paths become structurally incapable of disagreeing.

The two branches must still answer identically. A test asserting that is cheap and worth adding: build one action with three upcoming occasions, read `nextOccurrenceAt()` cold, then again on a freshly eager-loaded copy, and assert the two agree. Pin `recurrence` and `series_started_at` at the call site — `ActionFactory` randomises both.

In `IntentionController::actionLayer()`, add the eager load to the existing chain:

```php
->with('upcomingOccurrences')
```

It goes on unconditionally, beside the `->when($configuresActions, ...)` clause, because every action in the layer reads `next_occurrence_at` whether or not the loop configures a routine.

Extend `actionLayer`'s docblock with one line saying why the load exists — the docblock already explains why the *routine* eager load names no columns, and this is the second load in the same method.

- [ ] **Step 4: Prove the mutation, verify**

```bash
php artisan test --compact --filter=IntentionScreensTest
php artisan test --compact
php artisan test --compact --filter=PlainLoopIsUnchangedTest
vendor/bin/pint --dirty --format agent
```

`nextOccurrenceAt()` has four callers — `IntentionResource`, `IntentionController`, `Api\ActionController`, `DescribesActionShape`. The full suite is the guard that the other three are unaffected; run it, do not reason about it.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "perf(loops): load every action's next occasion in one query"
```

---

### Task 8: `RoutineRow.move()` has no in-flight guard

**Files:**
- Modify: `resources/js/patyourself/training/routine-editor.tsx:78-138`
- Test: `resources/js/patyourself/training/routine-editor.test.tsx` (extend)

`move()` fires `router.patch` with no guard. Two fast reorders both compute their order from the same stale `rows` prop, so the second overwrites the first and the first move is lost. The payload is the whole order and the server refuses a partial one, so nothing corrupts — a move simply vanishes, which is worse than an error because nothing says so.

- [ ] **Step 1: Write the failing test**

```
does not fire a second reorder while the first is still in flight
re-enables the controls once the reorder finishes
```

Mock `router.patch` so it does not resolve immediately, click ↓ twice, and assert `router.patch` was called **once**. Then invoke the captured `onFinish` and assert the buttons are enabled again. Read the file's existing `router` mocking setup and reuse it.

**Killing mutation:** remove the guard — `router.patch` is called twice and the first test goes red.

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement**

```tsx
const [reordering, setReordering] = useState(false);

/** The whole order with `index` moved by one step, as ReorderRoutine wants it. */
function move(by: -1 | 1) {
    if (reordering) {
        return;
    }

    const reordered = rows.map((each) => each.id);
    const [moved] = reordered.splice(index, 1);
    reordered.splice(index + by, 0, moved);

    setReordering(true);

    router.patch(
        routine.reorder.url(actionId),
        { order: reordered },
        {
            preserveScroll: true,
            onFinish: () => setReordering(false),
        },
    );
}
```

and add `|| reordering` to both buttons' `disabled`. The early return and the disabled attribute are **both** needed: the attribute is what the user sees, the return is what holds when a click lands in the same tick as the state update.

`onFinish` rather than `onSuccess`: a refused reorder must re-enable the controls too, or a single validation failure leaves the row frozen until reload.

- [ ] **Step 4: Verify and commit**

```bash
npx vitest run resources/js/patyourself/training/routine-editor.test.tsx
npx vitest run
npx tsc --noEmit
npm run lint
git add -A && git commit -m "fix(gym): do not lose a reorder to a second fast tap"
```

---

### Task 9: Two small lies in the recording path

Both are one-line fixes with real consequences. Kept in one task because neither carries a test cycle worth a separate reviewer gate.

**Files:**
- Modify: `resources/js/pages/training/exercise.tsx:109`
- Modify: `app/Services/Training/LastPerformance.php:59`
- Test: `resources/js/pages/training/exercise.test.tsx` (extend), `tests/Feature/Training/LastPerformanceTest.php` (extend)

**Defect A — an exercise with no routine row offers nowhere to record.** `exercise.tsx` passes `targetSets={exercise.target_sets ?? performedSets.length}`. `SetGrid` computes `pendingCount = max(targetSets - performedSets.length, 0)`, so with no `ActionExercise` row and nothing yet recorded that is `0 − 0 = 0`: **no open row at all**, on a screen whose entire purpose is recording. Reachable by removing an exercise from the routine mid-session.

**Defect B — `LastPerformance` sorts by `scheduled_for` with no tiebreaker.** Two occasions can share a `scheduled_for` (different actions, same slot, same exercise in both routines), and which one is "last" is then whatever the engine returns — differing between SQLite here and MySQL in production. The debt note filed this against `set-grid.tsx`; the ordering is actually in `LastPerformance.php:59`, and that is where it is fixed.

- [ ] **Step 1: Write the failing tests**

In `exercise.test.tsx`:

```
offers an open row when the exercise has no routine target and nothing recorded
offers an open row when the exercise has no routine target and sets already recorded
```

Render with `target_sets: null, target_reps: null` and assert `set-row-open-1` exists; then with two settled sets and assert `set-row-open-3` exists.

In `LastPerformanceTest.php`:

```php
public function test_two_occasions_sharing_a_scheduled_for_resolve_deterministically(): void
```

Two occasions, same `scheduled_for`, both recording the exercise, different weights. Assert the higher-id one is returned. **Pin `ActionFactory`** and use the **clock-scheduled** path.

**Killing mutations:** revert `+ 1` — the first two tests go red with no open row. Remove `orderByDesc('occurrences.id')` — run the third test repeatedly against the same seeded data and show it return the other occasion. If SQLite happens to return the same order without the tiebreaker, **say so in the report** and keep the test: it pins the intent, and MySQL is the engine that differs. Do not claim a mutation you could not make fail.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement**

`exercise.tsx`:

```tsx
{/* With no routine row there is no target to work down to, so the screen
    offers one open row past whatever is already recorded — a set at a
    time, for as long as the user keeps going. Without the `+ 1` the grid
    computes zero pending rows and a recording screen offers nowhere to
    record, which is reachable by taking an exercise off the routine
    mid-session. It is not a target: nothing here says how many sets there
    should be. */}
<SetGrid
    occurrenceId={occurrenceId}
    exerciseId={exercise.id}
    targetSets={exercise.target_sets ?? performedSets.length + 1}
    performedSets={performedSets}
/>
```

`LastPerformance.php` — add the tiebreaker beneath the existing sort and extend the docblock to say why:

```php
->orderByDesc('occurrences.scheduled_for')
->orderByDesc('occurrences.id')
```

- [ ] **Step 4: Verify and commit**

```bash
npx vitest run resources/js/pages/training/exercise.test.tsx
php artisan test --compact --filter=LastPerformanceTest
npx vitest run && npx tsc --noEmit && npm run lint
php artisan test --compact
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "fix(gym): always offer a row to record into, and settle a tied sort"
```

---

### Task 10: The vocabulary list, and full verification

**Files:**
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

- [ ] **Step 1: Add this batch's new source files**

Alphabetically, into `sourceFiles()`, matching the existing block's ordering:

- `app/Http/Controllers/Training/ProgressionController.php`
- `app/Services/Training/ExerciseHistory.php`
- `resources/js/pages/training/progression.tsx`
- `resources/js/patyourself/verdict-form.tsx`

`verdict-form.tsx` is not a training file but it now holds the outcome labels and the reason copy for **every** screen in the app — which is exactly the kind of user-facing copy the list exists to scan, and it was previously covered three times over by the pages it was extracted from. Say so in a comment beside it, or a future reader will read it as a stray entry.

- [ ] **Step 2: Prove the list bites, by name**

Plant `streak` in a comment in `resources/js/pages/training/progression.tsx`, run, confirm the failure **names `progression.tsx`**, remove it. Repeat with `percentage` in `app/Services/Training/ExerciseHistory.php` and confirm the failure names `ExerciseHistory.php` — `percent` is a substring trap and that is the point.

Paste both raw failures into the report.

- [ ] **Step 3: Full verification, all five**

```bash
npm run build
php artisan test --compact
npx vitest run
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
npm run lint
```

- PHP must **exceed** 981/6125 and must not shrink.
- JS will have moved from 419 — **state the number**, do not assume it.
- `tsc` must be **0** errors (Task 6 removed the one).
- `npm run lint` must be clean **without** `--fix`. If `--fix` changes files, commit what it changed.

- [ ] **Step 4: Run the invariants by name**

```bash
php artisan test --compact --filter=PlainLoopIsUnchangedTest
php artisan test --compact --filter=GymEconomyTest
php artisan test --compact --filter=WorkflowInvariantTest
php artisan test --compact --filter=McpEndpointTest
```

`McpEndpointTest` is there because nine of its cases fail with `Invalid key supplied` when the Passport keys are missing from the worktree — a green run here is also the evidence that the environment was set up correctly and that no failure in it is environmental.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "test(gym): scan batch 3's new source files for the banned register"
```

---

### Task 11: Whole-branch review and integration

- [ ] Request a whole-branch review over `origin/main..HEAD`, pointed at both specs, at `docs/BLOB.md`, and specifically at:
  - whether the progression screen crosses the record/prescribe line anywhere,
  - whether the verdict-form extraction changed any shipped behaviour rather than only its location,
  - whether every mutation claimed in every report was actually run — **verify each attribution against source; it is the one defect class reading the code cannot surface**.
- [ ] Act on findings, re-verify with all five commands, **re-capture any mutation table touched by a fix**.
- [ ] Report the three design calls from "The design calls this plan makes" explicitly, so the owner can overrule them. The first — omitting target sets/reps — deviates from the ClickUp ticket's wording and must be named as such.
- [ ] **ASK BEFORE PUSHING TO MAIN.** No migrations in this batch, so the owner needs only `git pull` — say so explicitly.
- [ ] Update ClickUp `14ykddrwu5y` to complete with a comment covering what shipped, the three design calls, and what the module still does not do.

---

## Self-review

**Spec coverage.** The Progression section is Tasks 1–4: one screen, per exercise, newest first, no chart, nothing computed. The spec's Error-handling bullets that touch this screen — a deleted exercise keeps its sets, a strategy revision leaves earlier sets readable, body weight is null not zero — each have a named test in Task 1 or 3. The architecture spec's read-model home (`App\Services\Training\`) is honoured, and `LastPerformance` is read and extended rather than duplicated (Task 9), which the prompt asked for directly.

**Deliberately not done.** Journalling as a second workflow — it has a sketch, not a spec, and needs brainstorming first. Logging a session over MCP — the spec lists it as out of scope for v1. The action-keyed verdict routes that can still split a session from its outcome (`ActionLogController`, `Api\ActionLogController`) — recorded in the architecture spec as deliberately unsolved, unreachable from the shipped web UI, and the right fix needs a question the slot layer cannot ask. Revoking `ANTHROPIC_API_KEY` — independent of any batch and not a code change.

**Known risk.** Task 6 edits the two screens every loop in the app shares, and Task 7 edits a model method with four callers. Both name `PlainLoopIsUnchangedTest` by name and both run the full suite; that is the guard, and it is the widest blast radius in the batch.

**Second known risk.** Task 5 may find that one or more of the three docblock claims is true as written, in which case the task's deliverable is a confirmation and a small prose repair rather than a correction. That is a valid outcome and the report must not manufacture a defect to justify the task.
