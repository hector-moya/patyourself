# Gym — Batch 2: recording

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make batch 1's spine reachable — a routine you can build, a session you can record set by set, and a verdict that lands on the occasion the sets are attached to.

**Architecture:** Three server surfaces (materialise, routine CRUD, set writing), one extension to the client workflow seam so a materialised occasion can be handed back up to the verdict form, and two screens. The recording surface registered at `WORKFLOWS.gym` is a **compact entry point** on the existing occasion cards; the working screens are dedicated pages.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3, React 19, Tailwind v4, Wayfinder, PHPUnit 12 (SQLite in-memory), Vitest, MySQL in production, Pint.

**Spec:** `docs/superpowers/specs/2026-09-04-training-module-design.md`
**Governing architecture:** `docs/superpowers/specs/2026-09-05-workflow-architecture-design.md`
**Batch 1 (merged, `3f828a1..2011dab`):** `docs/superpowers/plans/2026-09-06-gym-spine.md`
**The economy this must not break:** `docs/BLOB.md`

---

## The one design call this plan makes

The spec sketches two screens. The architecture built `WorkflowRecord` as an **inline slot** rendered above the verdict controls on every card that logs an outcome (`resources/js/pages/dashboard.tsx:217`, `resources/js/pages/catch-up.tsx`). The spec does not say how those relate.

**Resolved:** the inline slot draws a compact one-line entry point — the routine's name and how far through it you are, linking to the session page. The session and exercise screens are dedicated Inertia pages. A dashboard card cannot hold a weight/reps grid for four exercises, and the spec's own words are "two levels, because that is how a session is actually used… A single scrolling page of every field at once is a form; this is a checklist you work down."

If that is wrong, this document is the thing to change, not code.

---

## Global Constraints

Carried from the specs and from batch 1's whole-branch review. Every task's requirements implicitly include this section.

- **ONE OCCASION PRODUCES EXACTLY ONE `ActionLog`.** A forty-set session is worth what a glass of water is worth.
- **Recording does not log.** Writing `PerformedSet` rows moves `logCount` by zero. **Materialising does not log either.**
- **Entering sets does not decide the outcome.** You still press Done or Missed, and the reason field still appears on failure. Filling in three sets and then marking the session missed because you cut it short is a real thing that happens; an app that inferred "done" from the presence of data would be overruling the person who was there.
- **Record, never prescribe.** Last session's numbers on screen, yes. No suggested weight, no PR, no 1RM, no percentage, no trend called progress. Target sets/reps are shown **because the user wrote them**.
- **v1 ships no images.** `exercises.image_path` stays null. Nothing fetches at runtime.
- **Weight is kilograms, decimal. Body weight is NULL, never zero.**
- **`Strategy`, `Action`, `Occurrence`, `ActionLog` and `Intention` gain NO columns.** This batch adds no tables and no columns.
- **Banned vocabulary** — `CompanionVocabularyTest` scans listed files including comments: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. **`points` is a substring trap** — "extension points" and "endpoints" both contain it. Write "extension site" or singular "extension point".
- **PHPUnit, not Pest.** `php artisan test --compact --filter=Name`. **Never `vendor/bin/pint --test`** — use `vendor/bin/pint --dirty --format agent`.
- Models use `#[Fillable([...])]`, never `$guarded`. Services are `final readonly class`. Explicit return types and param hints; curly braces always; PHPDoc over inline comments.
- **Wayfinder** — import route helpers from `@/actions/` or `@/routes/`, never hardcode a URL.

### The two constraints batch 1's review handed this batch

Both are recorded in the architecture spec's **Error handling** section. Neither is optional.

1. **The recording surface must pass its occurrence to `LogAction`.** `MaterialisesOccasion::forAction()` guarantees the occasion the *sets* land on, not the one the *verdict* lands on. `LogAction::handle(User, Action, array, ?Occurrence $occurrence = null)` takes it as its fourth parameter. In the UI this means the card must post to `/occurrences/{id}/logs`, not `/actions/{id}/logs`, once an occurrence exists. Sharper than it sounds: with an unlogged *earlier* slot today, a session materialised on the 19:00 slot and a verdict pressed at 18:30 without naming the occurrence lands on the **07:00** slot.

2. **Set numbering continues from the existing max, never restarts at 1.** `performed_sets` has `unique(occurrence_id, exercise_id, set_number)`, and two sessions on the same day with no verdict between them deliberately resolve to the **same** occasion. A second session recording the same exercise must continue numbering or the insert throws a `QueryException`. This is a certainty from the schema.

### Baseline — measured on `main` at `8dc72ae`

| Check | Baseline | Command |
| --- | --- | --- |
| PHP tests | **914 passed**, 5157 assertions | `php artisan test --compact` |
| JS tests | **363 passed** | `npx vitest run` |
| TypeScript | **exactly 1 error**, `resources/js/pages/catch-up.tsx(139,33)` — pre-existing, out of scope, must not grow | `npx tsc --noEmit` |
| Pint | clean | `vendor/bin/pint --dirty --format agent` |

Run `npm run build` **before** the PHP suite or `PwaManifestTest` skips and the assertion count drops. This batch changes JS heavily, so 363 **will** move — every later task must state the new number rather than assume it.

### Traps that have each cost a round on this project

1. **A test written against a fixture's default asserts nothing.** Five shipped here; three more were caught in review. **Name the killing mutation for every test, and actually run it.** Three of six batch-1 reports claimed a mutation they had not run — each was caught by checking whether the failure message's assertion shape matched the assertion it was attributed to (`Failed asserting that false is true` = a failed `assertTrue`). The method that worked: freeze the file, hash it, run mutations against the frozen copy, build the report by concatenating raw captures.
2. **Every batch-1 gym test used `ActionFactory::anchored()`**, which is exactly why the occasion-splitting defect survived six reviews. **Cover the clock-scheduled path.**
3. **`assertDatabaseMissing` on a column that does not exist is a constant-false predicate** — SQLite degrades it to a string literal and it passes forever; MySQL errors.
4. **Column-limited eager loads hide new columns** — `->with('rel:id,title')` returns null for anything unnamed, forever, suite green. Bitten three times. **Grep for `:id,` before relying on a relation's column.**
5. **A relation read inside a loop is an N+1.** Copy the guard in `IntentionScreensTest::test_the_experiment_ladder_costs_the_same_however_many_versions_it_has`.
6. **`ActionFactory` is nondeterministic** — `recurrence` is `randomElement([null,'daily','weekdays'])`, `series_started_at` random −3..+4 days. Pin both.
7. **`ActionExerciseFactory` / `PerformedSetFactory` sequence their unique columns in `configure()`**, not `definition()` — `->count(3)` numbers itself. A bare `->create()` still lands on 1.
8. **Feature tests that render an Inertia view need `$this->withoutVite()`.**
9. **Pint's `fully_qualified_strict_types` rewrites an inline `::class` into a real `use` import.** If you assert a class does NOT exist, name it as a string literal.

---

## File Structure

**Created — server**

| File | Responsibility |
| --- | --- |
| `app/Http/Controllers/Training/SessionController.php` | The session page, and materialising its occasion. |
| `app/Http/Controllers/Training/PerformedSetController.php` | Writing and clearing one set. |
| `app/Http/Controllers/Training/RoutineController.php` | The routine (`ActionExercise`) setup surface. |
| `app/Http/Requests/Training/StorePerformedSetRequest.php` | |
| `app/Http/Requests/Training/StoreRoutineExerciseRequest.php` | |
| `app/Services/Training/SessionScreen.php` | Read model: the routine plus what has been recorded, for one occasion. |
| `app/Services/Training/LastPerformance.php` | Read model: what was lifted last time, per exercise. |
| `app/Actions/Training/RecordSet.php` | The only place a `PerformedSet` is written. Owns the continue-from-max rule. |

**Created — client**

| File | Responsibility |
| --- | --- |
| `resources/js/patyourself/training/gym-record.tsx` | The compact inline entry point registered at `WORKFLOWS.gym`. |
| `resources/js/pages/training/session.tsx` | The session screen. |
| `resources/js/pages/training/exercise.tsx` | The exercise screen. |
| `resources/js/patyourself/training/set-grid.tsx` | The weight/reps/done rows, with carry-down. |
| `resources/js/patyourself/training/routine-editor.tsx` | Building a routine from the catalogue. |

**Modified**

| File | Change |
| --- | --- |
| `resources/js/patyourself/workflows.ts` | Register `gym`; add the config site and the materialise callback to the seam. |
| `resources/js/patyourself/workflow-record.tsx` | Pass the new callback through. |
| `resources/js/pages/dashboard.tsx` | Hold the materialised occurrence id so `logEndpoint` uses it. |
| `resources/js/pages/catch-up.tsx` | Same. |
| `app/Services/Scheduling/TodaysOccasions.php` | Stop double-counting a materialised cue-anchored action. |
| `routes/web.php` | The new routes. |
| `tests/Feature/Companion/CompanionVocabularyTest.php` | The new source files. |

---

## Task sequencing

Tasks 1–3 are server-and-seam work that is independently mergeable and closes both of batch 1's constraints. Tasks 4–7 are the screens. Task 8 is the sweep.

**If context or time runs short, tasks 1–3 are a coherent merge on their own** — they make the spine reachable and fix the known defect, without shipping a screen.

---

### Task 1: Materialising over HTTP, and the seam that hands the occasion back

**Files:**
- Create: `app/Http/Controllers/Training/SessionController.php` (the `materialise` action only in this task)
- Modify: `routes/web.php`
- Test: `tests/Feature/Training/MaterialiseEndpointTest.php`

**Interfaces:**
- Consumes: `App\Services\Workflows\MaterialisesOccasion::forAction(Action): Occurrence`.
- Produces: `POST /actions/{action}/session` → named `training.session.materialise`, returning the occurrence id.

- [ ] **Step 1: Write the failing tests**

```php
public function test_it_materialises_an_occasion_and_returns_it(): void
public function test_it_creates_no_log_and_moves_the_count_by_zero(): void
public function test_two_posts_in_the_same_second_return_the_same_occasion(): void
public function test_it_returns_the_existing_slot_for_a_scheduled_action_before_its_time(): void
public function test_another_users_action_is_refused(): void
```

The second is the load-bearing one: assert `ActionLog::count()` is 0 **and** `app(CompanionResolver::class)->forUser($user)->logCount` is 0. The count read through the resolver is what pins the economy; the table count alone is the weaker test.

The fourth is trap 2 — use a clock-scheduled action (`recurrence: 'daily'`, explicit `series_started_at`), travel to before its slot, and assert the returned occurrence **is** the existing slot rather than a new one.

- [ ] **Step 2: Run to verify they fail.** Expected: route not defined.

- [ ] **Step 3: Implement the controller and route**

Authorise the action belongs to the requesting user's loop — copy the authorisation shape from `app/Http/Controllers/ActionLogController.php` rather than inventing one. Return the occurrence id in a shape the client can read back (an Inertia redirect carrying it, or a JSON response — match whatever `QuickLogController` and `OccurrenceLogController` already do for their callers).

- [ ] **Step 4: Prove the mutation**

Have the controller create an `ActionLog` alongside the occurrence. Test 2 must go red. Paste the real output. That is the mutation a future module would actually make.

- [ ] **Step 5: Full suite, pint, commit**

---

### Task 2: The client seam carries the materialised occasion up to the verdict

**This task closes batch 1's first constraint.** It is the reason the spine's defect cannot recur in the UI.

**Files:**
- Modify: `resources/js/patyourself/workflows.ts`
- Modify: `resources/js/patyourself/workflow-record.tsx`
- Modify: `resources/js/pages/dashboard.tsx`
- Modify: `resources/js/pages/catch-up.tsx`
- Test: `resources/js/patyourself/workflow-record.test.tsx` (extend), `resources/js/pages/dashboard.test.tsx` (extend)

**Interfaces:**
- Produces: `WorkflowRecordProps` gains `onOccurrenceMaterialised: (occurrenceId: number) => void`.

**The problem, precisely.** `logEndpoint()` (`resources/js/pages/dashboard.tsx:190`) chooses:

```ts
return occasion.occurrence_id === null
    ? `/actions/${occasion.action_id}/logs`
    : `/occurrences/${occasion.occurrence_id}/logs`;
```

`occurrence_id` is server-rendered. If the gym surface materialises an occasion after that render, the prop is stale and the verdict posts to the **action** route — which resolves its own slot via `liveSlotFor` and can land on a different occasion from the sets. That is exactly the defect batch 1's review found, arriving through the UI instead of the service.

- [ ] **Step 1: Write the failing tests**

In `workflow-record.test.tsx`: a registered surface that calls `onOccurrenceMaterialised(42)` results in the callback reaching the host. A plain loop still draws nothing and never calls it. The error boundary still swallows a throw.

In `dashboard.test.tsx`: an occasion rendered with `occurrence_id: null` whose surface materialises 42 must post the verdict to `/occurrences/42/logs`, **not** `/actions/{id}/logs`.

**Name the killing mutation:** drop the state update and keep using the prop — the dashboard test posts to the action route and fails.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement**

Add the callback to `WorkflowRecordProps` and thread it through `WorkflowRecord`. In `dashboard.tsx` and `catch-up.tsx`, hold the materialised id in `useState` seeded from the prop, and have `logEndpoint` read the state. Keep `logEndpoint`'s existing docblock reasoning — extend it, do not replace it.

The error boundary's `key={workflow}` behaviour and its documented reasoning must survive unchanged.

- [ ] **Step 4: Prove the mutation, then run `npx vitest run` and `npx tsc --noEmit`.**

The JS count moves; record the new number. `tsc` must still be exactly 1 error.

- [ ] **Step 5: Commit**

---

### Task 3: Stop double-counting a materialised cue-anchored action

**Files:**
- Modify: `app/Services/Scheduling/TodaysOccasions.php`
- Test: `tests/Feature/Scheduling/TodaysOccasionsTest.php` (extend — check the real filename first)

**The defect, recorded in the architecture spec and deliberately left for this batch.** `TodaysOccasions::for()` builds its cue-anchored branch from:

```php
$anchoredQuery = Action::query()->whereNull('series_started_at');
```

with **no check for whether an occurrence now exists** (`app/Services/Scheduling/TodaysOccasions.php:67`). Once a cue-anchored action has been materialised, the new unlogged occurrence is returned by the scheduled branch **and** the action is returned again by the anchored branch. It appears twice — on the dashboard, in the daily digest, and in the `today-actions` MCP tool, all three of which read this one service.

Two cards mean two verdicts mean `logCount` +2 for one session. The unique index on `action_logs.occurrence_id` does not stop it, because they are two different occasions.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_materialised_cue_anchored_action_appears_once(): void
```

Materialise an occasion for a cue-anchored action, then assert `TodaysOccasions::for($user)` returns **one** entry for that action, and that it is the scheduled entry carrying the occurrence (not the anchored one carrying null) — because the card must post to the occurrence route.

Also assert the untouched case still holds: a cue-anchored action with **no** occurrence still appears exactly once, as `ANCHORED`.

- [ ] **Step 2: Run to verify it fails.** Expected: 2 entries.

- [ ] **Step 3: Implement**

Exclude from the anchored branch any action that already has an unlogged occurrence inside the same local-day window the scheduled branch uses. Keep `restrictToUsersActiveLoops` applied identically to both branches — its docblock says why, and that reasoning still holds.

**Watch the N+1** (trap 5): this must not become a relation read per action.

- [ ] **Step 4: Prove the mutation**

Remove the exclusion; the new test goes red with 2 entries. Run the digest and MCP `today-actions` tests too — they read the same service and must not move.

- [ ] **Step 5: Full suite, pint, commit**

---

### Task 4: The routine — building it, and reading it back

**Files:**
- Create: `app/Http/Controllers/Training/RoutineController.php`, `app/Http/Requests/Training/StoreRoutineExerciseRequest.php`
- Create: `app/Services/Training/SessionScreen.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Training/RoutineTest.php`, `tests/Feature/Training/SessionScreenTest.php`

**Interfaces:**
- Produces: `SessionScreen::for(Occurrence $occurrence): array` — the routine in `position` order, each entry carrying the exercise, `target_sets`, `target_reps`, and the sets already recorded.

- [ ] **Step 1: Write the failing tests**

Routine: add an exercise to an action; positions are contiguous and unique; reorder; remove; an exercise from another user's catalogue is refused (use `Exercise::availableTo`); `target_reps` is a single integer and rejects a range string.

`SessionScreen`: returns the routine in position order with recorded sets attached; an action with no routine returns empty rather than erroring (the spec: "An action with no template logs exactly as it does today. The tracker is additive"); **and it costs the same number of queries for four exercises as for one** — copy the N+1 guard from `IntentionScreensTest::test_the_experiment_ladder_costs_the_same_however_many_versions_it_has`.

**Trap 4 applies directly here:** if you eager-load the exercise, name every column the screen reads. A `->with('exercise:id,name')` will return null for `instructions` forever with the suite green.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement.**

- [ ] **Step 4: Prove the mutations** — including the N+1 one: remove the eager load and assert the query-count test goes red.

- [ ] **Step 5: Full suite, pint, commit**

---

### Task 5: Recording a set

**Files:**
- Create: `app/Actions/Training/RecordSet.php`, `app/Http/Controllers/Training/PerformedSetController.php`, `app/Http/Requests/Training/StorePerformedSetRequest.php`
- Create: `app/Services/Training/LastPerformance.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Training/RecordSetTest.php`

**This task owns batch 1's second constraint.**

**Interfaces:**
- Produces: `RecordSet::handle(Occurrence $occurrence, Exercise $exercise, int $reps, ?float $weight): PerformedSet`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_it_numbers_the_first_set_one(): void
public function test_it_continues_numbering_from_the_existing_max(): void
public function test_a_second_session_on_the_same_occasion_does_not_restart_at_one(): void
public function test_a_body_weight_set_records_null_not_zero(): void
public function test_a_set_with_reps_and_no_weight_is_valid(): void
public function test_a_set_with_neither_is_refused(): void
public function test_recording_a_set_creates_no_log_and_moves_the_count_by_zero(): void
public function test_forty_sets_across_four_exercises_still_create_no_log(): void
```

The third is the constraint: `unique(occurrence_id, exercise_id, set_number)` means restarting at 1 throws a `QueryException`. Assert the numbering, not just the absence of an exception — an implementation that silently swallowed the error would pass a weaker test.

The fourth and fifth encode "a set with reps but no weight is valid (body weight); a set with neither is not recorded" from the spec's Error handling.

The last two are the economy guards, asserted through `CompanionResolver` as well as the table.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement.** `RecordSet` is the only place a `PerformedSet` is written. Derive `set_number` from `max(set_number) + 1` scoped to `(occurrence_id, exercise_id)` **inside a transaction**, so two quick taps cannot collide — the same hazard `freeSlotAt`'s `firstOrCreate` solves for occasions.

- [ ] **Step 4: Prove the mutations** — especially: reset numbering to `count() + 1` and show the same-occasion test go red.

- [ ] **Step 5: Full suite, pint, commit**

---

### Task 6: The session screen

**Files:**
- Create: `resources/js/pages/training/session.tsx`, `resources/js/patyourself/training/gym-record.tsx`
- Modify: `resources/js/patyourself/workflows.ts` (register `gym`)
- Modify: `app/Http/Controllers/Training/SessionController.php` (the `show` action)
- Test: `resources/js/pages/training/session.test.tsx`, `tests/Feature/Training/SessionScreenRenderTest.php`

The layout, from the spec:

```
Upper A · Wednesday 10 September

  Bench press          3 x 10     ✓ ✓ ·
  Barbell row          3 x 10     · · ·
  Lat pulldown         3 x 12     · · ·
  Face pull            3 x 15     · · ·

                          [ Done ]   [ Missed ]
```

- [ ] **Step 1: Write the failing tests**

Renders the exercises in position order with target sets/reps and a dot per set, filled to the number recorded. Tapping an exercise navigates to the exercise screen. The verdict controls post to the **occurrence** route. An action with no routine renders the plain verdict controls and no tracker.

`gym-record.tsx` renders one compact line and calls `onOccurrenceMaterialised` when recording begins.

**Nothing on this screen may suggest a weight, name a record, or show a percentage.** Assert the absence of that vocabulary in the rendered output, not only in the source.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement.** Feature tests rendering this need `$this->withoutVite()`. Use Wayfinder route helpers, never a hardcoded URL.

- [ ] **Step 4: `npx vitest run`, `npx tsc --noEmit` (still exactly 1), pint, commit**

---

### Task 7: The exercise screen

**Files:**
- Create: `resources/js/pages/training/exercise.tsx`, `resources/js/patyourself/training/set-grid.tsx`
- Test: `resources/js/pages/training/exercise.test.tsx`, `resources/js/patyourself/training/set-grid.test.tsx`

The layout, from the spec:

```
Bench press                              target 3 x 10
                              last  60kg · 10 / 10 / 8 · 2 Sep

   weight     reps      done
   [ 60 ]kg   [ 10 ]     ☑
   [ 60 ]kg   [ 10 ]     ☑
   [ 60 ]kg   [    ]     ☐

Lower to the chest under control, press to lockout.
```

- [ ] **Step 1: Write the failing tests**

Weight carries down from the set above. **It is a default, not a target** — every field stays editable, and changing a lower row does not change the one above. Last session's numbers render when there is history and the row is absent when there is none. The imported instruction renders. An exercise with no image renders without one and does not error. Body weight shows as blank, never `0`.

**The carry-down is the one with a subtle killing mutation:** make it carry *up* as well, and the "changing a lower row does not change the one above" test goes red.

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement.**

- [ ] **Step 4: `npx vitest run`, `npx tsc --noEmit`, pint, commit**

---

### Task 8: The vocabulary list, and the batch's verification

**Files:**
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

- [ ] **Step 1: Add every new source file this batch created** — server and client both. The client files matter most: they hold user-facing copy, which is where scoring vocabulary actually lands.

- [ ] **Step 2: Prove the list bites, by name.** Plant `streak` in a comment in `resources/js/pages/training/session.tsx`, run, confirm the failure **names `session.tsx`**, remove it. Repeat once for a server file with `percent`.

- [ ] **Step 3: Full verification**

```bash
npm run build
php artisan test --compact
npx vitest run
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
```

PHP must exceed 914/5157 and must not shrink. **JS will have moved** — state the number. `tsc` must still be **exactly 1** error at `catch-up.tsx(139,33)`.

---

### Task 9: Whole-branch review and integration

- [ ] Request a whole-branch review over `origin/main..HEAD`, pointed at both specs and at the two constraints in Global Constraints — whether they are genuinely closed, not merely tested.
- [ ] Act on findings, re-verify.
- [ ] **Ask before pushing to main.** Then `git push origin HEAD:main`. No new migrations in this batch, so the owner needs only `git pull` — say so explicitly rather than repeating batch 1's migrate-and-seed instructions.
- [ ] Update ClickUp `14ykddrwu5x` and note what batch 3 (`14ykddrwu5y`) still holds.

---

## Self-review

**Spec coverage.** The session screen (task 6), the exercise screen (task 7), the routine setup (task 4), weight carry-down (task 7), last performance shown (tasks 5, 7), sets survive a strategy revision (batch 1 already pins this), a failed session still carries its sets (batch 1), the tracker being additive for an action with no routine (tasks 4, 6), and the vocabulary list (task 8). **Both batch-1 constraints have a task that owns them** — task 2 for the verdict's occasion, task 5 for set numbering.

**Deliberately deferred.** The progression screen and its read models (batch 3, `14ykddrwu5y`). Logging a session over MCP — the spec lists it as out of scope for v1, and it stays out.

**Known risk.** The design call at the top. If the recording surface should be inline rather than two pages, tasks 6 and 7 change shape and tasks 1–5 do not — which is why the server work is sequenced first.

**Second known risk.** Task 3 changes `TodaysOccasions`, which the dashboard, the daily digest and the `today-actions` MCP tool all read. Its blast radius is the widest in the batch, and the existing tests for all three are the guard.
