# Amendable action layer — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the owner rename an action, change its cadence, and edit a routine row's target sets and reps — none of which is possible in the app today.

**Architecture:** Two server changes and two UI surfaces. `RescheduleAction` gains a guard so rescheduling to an unchanged schedule stops purging occasions; `PATCH actions/{action}` widens to accept a title and description, reaching the rescheduler only when a schedule field arrives. A fourth writer, `UpdateRoutineExercise`, sets a routine row's targets without touching its position. Both UI surfaces use tap-to-edit with an explicit Save.

**Tech Stack:** Laravel 13, PHP 8.4, Inertia v3, React 19, Tailwind v4, PHPUnit, vitest + @testing-library/react.

**Spec:** `docs/superpowers/specs/2026-09-13-amendable-action-layer-design.md`

## Global Constraints

- **Copy rules:** sentence case, no exclamation marks, never congratulating, no second person keeping score.
- **`resources/js/patyourself/loops/action-layer.tsx` and `resources/js/patyourself/training/routine-editor.tsx` are both on `CompanionVocabularyTest::sourceFiles()`.** That test scans them — **comments included** — for: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` and `percent` are substring traps: *endpoints* and *percentage* both trip them. No new file in this plan needs registering — both frontend files are already listed and the new PHP files are backend.
- **Editing a target must never be framed against what was performed.** The routine is a note of intent; the edit form says what it will be, never how it compares to what happened.
- **No migration, no model change.** `action_exercises` and `actions` keep their columns.
- Run `npm run build` before `php artisan test` or `PwaManifestTest` skips itself and ~470 assertions vanish.
- Baseline to hold: **1015 PHP tests / 6452 assertions, 486 JS tests, 0 TypeScript errors.** Both counts rise.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `app/Actions/RescheduleAction.php` | modify — return untouched when the schedule is unchanged |
| `app/Http/Requests/RescheduleActionRequest.php` | modify — `kind` optional; `title` and `description` added |
| `app/Http/Controllers/ActionController.php` | modify — `update()` applies title/description, reschedules only when `kind` is present |
| `app/Actions/Training/UpdateRoutineExercise.php` | **new** — the only writer of a routine row's targets |
| `app/Http/Requests/Training/UpdateRoutineExerciseRequest.php` | **new** |
| `app/Http/Controllers/Training/RoutineController.php` | modify — `update()` |
| `routes/web.php` | modify — one route, declared after the reorder route |
| `resources/js/patyourself/loops/action-layer.tsx` | modify — per-action edit state |
| `resources/js/patyourself/training/routine-editor.tsx` | modify — per-row target edit state |
| `docs/GYM.md` · `docs/MCP.md` | modify — fold into Task 3 |

---

### Task 1: Rescheduling to an unchanged schedule stops purging

The action edit form carries title and schedule behind one Save, so the schedule fields are always sent — including on a pure rename. Without this guard, every rename would delete the action's future occasions.

**Files:**
- Modify: `app/Actions/RescheduleAction.php`
- Test: `tests/Feature/Actions/SeriesAnchorTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `RescheduleAction::handle(Action $action, string $kind, ?string $time, ?string $recurrence, ?string $anchor, string $timezone): Action` — signature unchanged. New behaviour: returns `$action` unmodified, with no write and no purge, when the computed schedule matches the stored one.

- [ ] **Step 1: Write the failing test**

Append inside the existing `SeriesAnchorTest` class in `tests/Feature/Actions/SeriesAnchorTest.php`. Read the top of that file first for its existing imports and fixture helpers, and reuse them rather than adding your own.

```php
    /**
     * The edit form posts the schedule on every save, including a save that
     * only changed the title. Re-anchoring then would delete occasions the
     * user never asked to lose, so an unchanged schedule must do nothing at
     * all — not re-anchor, not purge.
     *
     * Killing mutation: drop the early return in RescheduleAction::handle().
     * The occurrence below is unlogged and in the future, which is exactly
     * what purgeAbandonedOccurrences() removes, so the assertion fails.
     */
    public function test_rescheduling_to_the_same_schedule_changes_nothing(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();

        $action = Action::factory()->for($loop)->for($strategy)->create([
            'recurrence' => 'daily',
            'series_started_at' => CarbonImmutable::parse('2026-09-14 08:00:00'),
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::now()->addDays(3),
        ]);

        $anchorBefore = $action->series_started_at;

        $rescheduled = app(RescheduleAction::class)->handle(
            $action,
            'clock',
            $action->series_started_at->setTimezone('Europe/London')->format('H:i'),
            'daily',
            null,
            'Europe/London',
        );

        $this->assertTrue(
            $anchorBefore->equalTo($rescheduled->series_started_at),
            'An unchanged schedule must not move the series anchor.',
        );
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }
```

- [ ] **Step 2: Run it and watch it fail**

Run: `php artisan test --compact --filter=test_rescheduling_to_the_same_schedule_changes_nothing`
Expected: FAIL — the occurrence row is gone, because `purgeAbandonedOccurrences()` removed it.

- [ ] **Step 3: Add the guard**

In `app/Actions/RescheduleAction.php`, after `$metadata` is computed (currently line 32) and **before** the `DB::transaction(...)` call, insert:

```php
        // The edit form posts title and schedule behind one Save, so the
        // schedule arrives on every save — including one that only changed the
        // title. Re-anchoring then would purge future occasions for a text
        // edit, so a schedule that resolves to what the action already has is
        // no reschedule at all.
        //
        // The guard lives here rather than in the client because a client that
        // forgot to diff would delete occasions silently, and the connector
        // reaches this writer by a different route. One place, both callers.
        //
        // Compared on the resolved values, not the submitted ones: two
        // different `time` strings can resolve to the same anchor, and the
        // anchor is what the grid is built from.
        $unchanged = $this->resolvesToTheSameSchedule($action, $scheduledFor, $rule, $metadata);

        if ($unchanged) {
            return $action;
        }
```

Then add the private method at the end of the class, before the closing brace:

```php
    /**
     * Whether the schedule just computed is the one the action already has.
     *
     * `series_started_at` is compared with `equalTo` rather than `===` because
     * one side is a Carbon instance off the model and the other is freshly
     * computed; two instants that are equal are not the same object. Null on
     * both sides is a match — that is a cue-anchored action staying anchored.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function resolvesToTheSameSchedule(
        Action $action,
        ?CarbonImmutable $scheduledFor,
        ?Recurrence $rule,
        array $metadata,
    ): bool {
        $current = $action->series_started_at?->toImmutable();

        $sameAnchor = $current === null && $scheduledFor === null
            ? true
            : $current !== null && $scheduledFor !== null && $current->equalTo($scheduledFor);

        $currentMetadata = $action->metadata ?? [];

        return $sameAnchor
            && $action->recurrence === $rule?->value
            && ($currentMetadata['schedule_kind'] ?? null) === ($metadata['schedule_kind'] ?? null)
            && ($currentMetadata['anchor'] ?? null) === ($metadata['anchor'] ?? null);
    }
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `php artisan test --compact --filter=test_rescheduling_to_the_same_schedule_changes_nothing`
Expected: PASS.

- [ ] **Step 5: Confirm the existing reschedule coverage still passes**

Every existing reschedule test changes the schedule, so none should be affected — this step proves that rather than assuming it.

```bash
php artisan test --compact tests/Feature/Actions/SeriesAnchorTest.php
php artisan test --compact tests/Feature/Actions/RescheduleActionWebTest.php
php artisan test --compact tests/Feature/Api/ActionRescheduleTest.php
php artisan test --compact tests/Feature/Mcp/ActionCrudToolsTest.php
```

Expected: all pass. If one fails, **stop and report it** — it means a test relied on an unchanged reschedule re-anchoring, which contradicts the spec and needs a ruling, not a quiet edit.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/RescheduleAction.php tests/Feature/Actions/SeriesAnchorTest.php
git commit -m "fix(actions): rescheduling to an unchanged schedule purges nothing"
```

---

### Task 2: The action endpoint accepts a title and description

**Files:**
- Modify: `app/Http/Requests/RescheduleActionRequest.php`
- Modify: `app/Http/Controllers/ActionController.php:20-34`
- Test: `tests/Feature/Actions/RescheduleActionWebTest.php`

**Interfaces:**
- Consumes: Task 1's guard — a request carrying an unchanged schedule alongside a new title must leave occasions alone.
- Produces: `PATCH actions/{action}` (`actions.update`) accepts `title`, `description`, `kind`, `time`, `recurrence`, `anchor`, all optional, and rejects a payload carrying none of them.

- [ ] **Step 1: Write the failing tests**

Append to the existing class in `tests/Feature/Actions/RescheduleActionWebTest.php`, reusing its imports and fixture style:

```php
    /**
     * The coach could already rename an action over MCP and the owner could
     * not in the app. Renaming must reach `$action->update()` and never the
     * rescheduler, which purges future occasions.
     *
     * Killing mutation: route the title through RescheduleAction, or drop the
     * title from the request's rules. Either way the title assertion fails.
     */
    public function test_owner_can_rename_an_action(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'title' => 'Upper body 2',
        ]);

        $this->actingAs($user)
            ->patch(route('actions.update', $action), ['title' => 'Upper body A'])
            ->assertRedirect();

        $this->assertSame('Upper body A', $action->fresh()->title);
    }

    /**
     * The edit form always posts the schedule, so a rename arrives carrying
     * one. Task 1's guard is what keeps the occasions; this asserts the two
     * work together over HTTP rather than only in the writer.
     */
    public function test_renaming_while_resubmitting_the_same_schedule_keeps_the_occasions(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/London']);
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create([
            'title' => 'Upper body 2',
            'recurrence' => 'daily',
            'series_started_at' => CarbonImmutable::parse('2026-09-14 08:00:00'),
            'metadata' => ['schedule_kind' => 'clock', 'anchor' => null],
        ]);

        $occurrence = Occurrence::factory()->for($action)->create([
            'scheduled_for' => CarbonImmutable::now()->addDays(3),
        ]);

        $this->actingAs($user)
            ->patch(route('actions.update', $action), [
                'title' => 'Upper body A',
                'kind' => 'clock',
                'time' => $action->series_started_at->setTimezone('Europe/London')->format('H:i'),
                'recurrence' => 'daily',
            ])
            ->assertRedirect();

        $this->assertSame('Upper body A', $action->fresh()->title);
        $this->assertDatabaseHas('occurrences', ['id' => $occurrence->id]);
    }

    /**
     * A payload changing nothing is a caller mistake, not a silent success —
     * `UpdateActionTool` already refuses one and the web endpoint matches it.
     */
    public function test_a_payload_that_changes_nothing_is_rejected(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();

        $this->actingAs($user)
            ->patch(route('actions.update', $action), [])
            ->assertSessionHasErrors();
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact tests/Feature/Actions/RescheduleActionWebTest.php`
Expected: the three new tests FAIL — `kind` is currently `required`, so a title-only payload is a validation error, and `title` is not in the rules so it is never applied.

- [ ] **Step 3: Widen the request**

Replace the `rules()` body in `app/Http/Requests/RescheduleActionRequest.php`:

```php
    /**
     * Every field is optional because this endpoint amends an action: a rename
     * carries no schedule, and a reschedule carries no title. `kind` gates the
     * schedule half — `required_with` on the rest is what stops a half-stated
     * schedule reaching the writer.
     *
     * A payload with nothing in it is refused in withValidator() rather than
     * here: "at least one of these" is not a per-field rule.
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:250'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'kind' => ['sometimes', 'in:clock,anchored'],
            'time' => ['nullable', 'required_if:kind,clock', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence' => ['nullable', 'in:once,daily,weekdays,weekly'],
            'anchor' => ['nullable', 'required_if:kind,anchored', 'string', 'max:255'],
        ];
    }

    /**
     * Refuses a payload that would change nothing. Without this the endpoint
     * answers a redirect to a request that did nothing, which reads as success.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $changes = array_intersect_key(
                $this->all(),
                array_flip(['title', 'description', 'kind']),
            );

            if ($changes === []) {
                $validator->errors()->add('title', 'Pass at least one field to change.');
            }
        });
    }
```

Add `use Illuminate\Validation\Validator;` to the file's imports.

- [ ] **Step 4: Apply the fields in the controller**

Replace `update()` in `app/Http/Controllers/ActionController.php` (currently lines 20–34):

```php
    /**
     * Amends an action: its wording, its schedule, or both.
     *
     * The two halves go to different writers on purpose. A title is a plain
     * column write; a schedule change re-anchors the series and purges the
     * grid it abandons, so routing a rename through the rescheduler would
     * delete future occasions for a text edit. `kind` is what says a schedule
     * was actually submitted — the same rule `UpdateActionTool` applies, so the
     * app and the connector amend an action the same way.
     */
    public function update(RescheduleActionRequest $request, Action $action, RescheduleAction $reschedule): RedirectResponse
    {
        Gate::authorize('update', $action);

        $fields = array_filter(
            [
                'title' => $request->validated('title'),
                'description' => $request->validated('description'),
            ],
            static fn ($value): bool => $value !== null,
        );

        if ($fields !== []) {
            $action->update($fields);
        }

        if ($request->validated('kind') !== null) {
            $reschedule->handle(
                $action,
                $request->validated('kind'),
                $request->validated('time'),
                $request->validated('recurrence'),
                $request->validated('anchor'),
                $request->user()->timezone ?? (string) config('app.timezone'),
            );
        }

        return back();
    }
```

- [ ] **Step 5: Run the tests**

```bash
php artisan test --compact tests/Feature/Actions/RescheduleActionWebTest.php
php artisan test --compact tests/Feature/Api/ActionRescheduleTest.php
```

Expected: all pass. The API test shares `RescheduleActionRequest`, so watch it specifically — if a case there relied on `kind` being required, **report it rather than editing it**.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/RescheduleActionRequest.php app/Http/Controllers/ActionController.php tests/Feature/Actions/RescheduleActionWebTest.php
git commit -m "feat(actions): let the owner rename an action, not just reschedule it"
```

---

### Task 3: A writer for routine targets

**Files:**
- Create: `app/Actions/Training/UpdateRoutineExercise.php`
- Create: `app/Http/Requests/Training/UpdateRoutineExerciseRequest.php`
- Modify: `app/Http/Controllers/Training/RoutineController.php`
- Modify: `routes/web.php:121-124`
- Modify: `docs/GYM.md`, `docs/MCP.md`
- Test: `tests/Feature/Training/` — add to the file covering routine writes; if none fits, create `RoutineTargetEditTest.php`

**Interfaces:**
- Produces: `UpdateRoutineExercise::handle(ActionExercise $actionExercise, int $targetSets, int $targetReps): ActionExercise`, and the route `actions.exercises.update` at `PATCH actions/{action}/exercises/{actionExercise}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Training/RoutineTargetEditTest.php`. Read a sibling in `tests/Feature/Training/` first and copy its class header, imports and `RefreshDatabase` usage.

```php
    /**
     * Editing exists because remove-and-re-add is the only way to change a
     * target today, and it drops the row to the end of the routine. Position
     * surviving is the point, so it is asserted, not assumed.
     *
     * Killing mutation: implement the writer as a delete plus a create. The
     * position assertion fails.
     */
    public function test_editing_a_target_keeps_the_row_in_its_place(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();

        $first = ActionExercise::factory()->for($action)->create([
            'position' => 1, 'target_sets' => 3, 'target_reps' => 10,
        ]);
        $second = ActionExercise::factory()->for($action)->create([
            'position' => 2, 'target_sets' => 3, 'target_reps' => 10,
        ]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $first]), [
                'target_sets' => 4,
                'target_reps' => 8,
            ])
            ->assertRedirect();

        $first->refresh();

        $this->assertSame(4, $first->target_sets);
        $this->assertSame(8, $first->target_reps);
        $this->assertSame(1, $first->position);
        $this->assertSame(2, $second->fresh()->position);
    }

    /**
     * Targets are the prescription; performed sets are what happened. Changing
     * one must never rewrite the other.
     */
    public function test_editing_a_target_leaves_recorded_sets_alone(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $exercise = Exercise::factory()->create();

        $row = ActionExercise::factory()->for($action)->create([
            'exercise_id' => $exercise->id,
            'position' => 1, 'target_sets' => 3, 'target_reps' => 10,
        ]);

        $occurrence = Occurrence::factory()->for($action)->create();
        $set = PerformedSet::factory()->create([
            'occurrence_id' => $occurrence->id,
            'exercise_id' => $exercise->id,
            'set_number' => 1,
            'reps' => 12,
        ]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 5,
                'target_reps' => 5,
            ])
            ->assertRedirect();

        $this->assertSame(12, $set->fresh()->reps);
    }

    /** A routine belongs to its loop's owner, like every other write here. */
    public function test_a_stranger_cannot_edit_a_target(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $loop = Intention::factory()->for($owner)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $row = ActionExercise::factory()->for($action)->create(['position' => 1]);

        $this->actingAs($stranger)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 4, 'target_reps' => 8,
            ])
            ->assertForbidden();
    }

    /**
     * The row must belong to the action in the URL. Without this check, owning
     * one action would be enough to edit another's routine.
     */
    public function test_a_row_from_another_action_is_unreachable(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $other = Action::factory()->for($loop)->for($strategy)->create();
        $row = ActionExercise::factory()->for($other)->create(['position' => 1]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 4, 'target_reps' => 8,
            ])
            ->assertNotFound();
    }

    /** Zero sets is not a routine row, it is a removal. */
    public function test_targets_below_one_are_refused(): void
    {
        $user = User::factory()->create();
        $loop = Intention::factory()->for($user)->create();
        $strategy = Strategy::factory()->for($loop)->create();
        $action = Action::factory()->for($loop)->for($strategy)->create();
        $row = ActionExercise::factory()->for($action)->create(['position' => 1]);

        $this->actingAs($user)
            ->patch(route('actions.exercises.update', [$action, $row]), [
                'target_sets' => 0, 'target_reps' => 10,
            ])
            ->assertSessionHasErrors('target_sets');
    }
```

- [ ] **Step 2: Run them and watch them fail**

Run: `php artisan test --compact tests/Feature/Training/RoutineTargetEditTest.php`
Expected: FAIL — `Route [actions.exercises.update] not defined.`

- [ ] **Step 3: Create the writer**

Create `app/Actions/Training/UpdateRoutineExercise.php`:

```php
<?php

namespace App\Actions\Training;

use App\Models\ActionExercise;

/**
 * Changes what one routine row targets — the gym workflow's config extension
 * site, amended. The only place a row's targets change.
 *
 * `position` is deliberately untouched. Before this existed the only way to
 * change a target was {@see RemoveRoutineExercise} followed by
 * {@see AddRoutineExercise}, and adding appends, so correcting a typo sent the
 * exercise to the end of the routine. Keeping the place is the reason this
 * writer exists at all.
 *
 * Nothing here reads or writes a {@see \App\Models\PerformedSet}. Targets are
 * the standing prescription and sets are what happened on one occasion; a
 * routine that said three while four were recorded is two true facts, and
 * amending the first must not rewrite the second.
 *
 * No transaction and no locking read, unlike its sibling writers: this changes
 * two columns on one row it was handed, computing nothing from the rest of the
 * routine, so there is no sequence for two concurrent taps to race over.
 */
final readonly class UpdateRoutineExercise
{
    public function handle(ActionExercise $actionExercise, int $targetSets, int $targetReps): ActionExercise
    {
        $actionExercise->update([
            'target_sets' => $targetSets,
            'target_reps' => $targetReps,
        ]);

        return $actionExercise->refresh();
    }
}
```

- [ ] **Step 4: Create the request**

Create `app/Http/Requests/Training/UpdateRoutineExerciseRequest.php`. Read `StoreRoutineExerciseRequest` first and match its class shape and `authorize()` convention.

```php
<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validating an edit to a routine row's targets.
 *
 * The rules mirror {@see StoreRoutineExerciseRequest}'s for the same two
 * fields, so a target that could be added can be edited to and back. There is
 * no `exercise_id` here: which exercise a row is for is not editable — that is
 * a different exercise, which is a remove and an add.
 *
 * Authorization is the controller's `Gate::authorize('update', $action)`, as it
 * is for every other write on this surface.
 */
class UpdateRoutineExerciseRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'target_sets' => ['required', 'integer', 'min:1'],
            'target_reps' => ['required', 'integer', 'min:1'],
        ];
    }
}
```

- [ ] **Step 5: Add the controller action**

In `app/Http/Controllers/Training/RoutineController.php`, add before `destroy()`, and add the two imports (`UpdateRoutineExercise`, `UpdateRoutineExerciseRequest`):

```php
    /**
     * Changes what one row of the routine targets.
     *
     * The `action_id` check is the same one {@see self::destroy()} makes and
     * for the same reason: route model binding resolves the row from the whole
     * table, so without it, owning one action would be enough to edit another
     * action's routine. A 404 rather than a 403 — a row that is not on this
     * action does not exist as far as this URL is concerned.
     */
    public function update(
        UpdateRoutineExerciseRequest $request,
        Action $action,
        ActionExercise $actionExercise,
        UpdateRoutineExercise $update,
    ): RedirectResponse {
        Gate::authorize('update', $action);

        abort_unless($actionExercise->action_id === $action->id, 404);

        $update->handle(
            $actionExercise,
            $request->integer('target_sets'),
            $request->integer('target_reps'),
        );

        return back();
    }
```

- [ ] **Step 6: Add the route — after the reorder route**

In `routes/web.php`, add immediately after the `actions.exercises.reorder` line (currently line 122):

```php
    Route::patch('actions/{action}/exercises/{actionExercise}', [RoutineController::class, 'update'])
        ->name('actions.exercises.update');
```

**The order matters.** `actions/{action}/exercises/reorder` is also a PATCH. Declared first, it matches `reorder` literally; declared after this one, the word `reorder` would bind as `{actionExercise}` and every reorder would 404. Put the new route below it, not above.

- [ ] **Step 7: Run the tests**

Run: `php artisan test --compact tests/Feature/Training/RoutineTargetEditTest.php`
Expected: PASS, 5 tests.

Then confirm reordering still routes:

Run: `php artisan test --compact --filter=Reorder`
Expected: PASS — this is the check that catches the route-ordering trap.

- [ ] **Step 8: Update the docs**

In `docs/GYM.md`:

- §6's writers table gains a row: `` `UpdateRoutineExercise` `` — "Changes one row's targets. Leaves `position` alone".
- §9's `app/Actions/Training/` line gains `UpdateRoutineExercise` to the list.
- §10 currently says nothing about routine targets being uneditable. Replace the bullet beginning "**No set editing or deletion.**" with:

```markdown
- **No performed-set editing or deletion.** `RecordSet` is the only writer of a
  `PerformedSet` and there is no update endpoint, which is why a recorded row
  renders as settled in the set grid. A mistyped set stays mistyped. A routine
  row's *targets* are editable — see §6 — but what was lifted is not.
```

In `docs/MCP.md` §11, add:

```markdown
- **No tool amends a routine row.** The connector can add and remove routine
  rows but not change one's targets; the app can, through
  `actions.exercises.update`. The mirror of the gap `create-loop` has with
  `workflow`, and open for the same reason — nobody has needed it yet.
```

- [ ] **Step 9: Commit**

```bash
git add app/Actions/Training/UpdateRoutineExercise.php app/Http/Requests/Training/UpdateRoutineExerciseRequest.php app/Http/Controllers/Training/RoutineController.php routes/web.php tests/Feature/Training/RoutineTargetEditTest.php docs/GYM.md docs/MCP.md
git commit -m "feat(gym): let a routine row's targets be edited in place"
```

---

### Task 4: Editing an action in the action layer

**Files:**
- Modify: `resources/js/patyourself/loops/action-layer.tsx`
- Test: `resources/js/pages/loops/show.test.tsx`

**Interfaces:**
- Consumes: `actions.update` from Task 2, reached through the Wayfinder helper `update` in `@/routes/actions`.
- Produces: an `Edit` button per action, and an edit form carrying `title`, `kind`, `time`, `recurrence`, `anchor` — **unprefixed**.

- [ ] **Step 1: Write the failing test**

Add inside `describe('LoopShow', …)` in `resources/js/pages/loops/show.test.tsx`:

```tsx
    /**
     * An action's wording and cadence were unreachable in the app — the coach
     * could change both over MCP and the owner could change neither.
     *
     * Tap to edit rather than always-live inputs: a mis-tap on a phone must not
     * silently rename an action or move its schedule.
     *
     * Killing mutation: render the edit form unconditionally. The first
     * assertion fails, because the title input would exist before Edit is
     * pressed.
     */
    it('edits an action only after the edit control is pressed', async () => {
        const user = userEvent.setup();

        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[actionRecord({ id: 3, title: 'Upper body 2' })]}
            />,
        );

        expect(
            screen.queryByLabelText(/what to do/i),
        ).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: /edit upper body 2/i }),
        );

        const title = screen.getByLabelText(/what to do/i);

        expect(title).toHaveValue('Upper body 2');
        expect(title.closest('form')).toHaveAttribute(
            'action',
            expect.stringContaining('/actions/3'),
        );
    });

    /**
     * Cancel restores the read state without a request. Asserted because the
     * alternative — leaving the form open — is what makes an accidental Edit
     * press feel like a trap.
     */
    it('closes the action edit form on cancel', async () => {
        const user = userEvent.setup();

        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[actionRecord({ id: 3, title: 'Upper body 2' })]}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: /edit upper body 2/i }),
        );
        await user.click(screen.getByRole('button', { name: /cancel/i }));

        expect(
            screen.queryByLabelText(/what to do/i),
        ).not.toBeInTheDocument();
    });
```

- [ ] **Step 2: Run and watch it fail**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx -t "edits an action"`
Expected: FAIL — no button matching `/edit upper body 2/i`.

- [ ] **Step 3: Add the edit state**

In `action-layer.tsx`:

1. Import the update helper beside the existing `destroy` import:

```tsx
import { destroy, update } from '@/routes/actions';
```

2. Add per-action edit state to `ActionLayer`. **Note the existing `const [kind, setKind] = useState(...)` belongs to the add form and must stay as it is** — the edit form needs its own, so give each action's editor its own component rather than widening that one:

```tsx
    const [editing, setEditing] = useState<number | null>(null);
```

3. In the `actions.map(...)` list item, replace the read-only header block with a branch. When `editing === action.id`, render `<ActionEditor>`; otherwise render the current header plus an Edit button:

```tsx
                        {editing === action.id ? (
                            <ActionEditor
                                action={action}
                                onDone={() => setEditing(null)}
                            />
                        ) : (
                            <div className="flex items-center justify-between gap-3">
                                <span>
                                    <span className="block">{action.title}</span>
                                    {action.cadence !== null && (
                                        <span className="block text-sm opacity-70">
                                            {action.cadence}
                                        </span>
                                    )}
                                </span>
                                <span className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Edit ${action.title}`}
                                        onClick={() => setEditing(action.id)}
                                    >
                                        Edit
                                    </Button>
                                    <Form {...destroy.form(action.id)}>
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Retire
                                            </Button>
                                        )}
                                    </Form>
                                </span>
                            </div>
                        )}
```

4. Add the editor component at the end of the file. Its field names are **unprefixed** — `title`, `kind`, `time`, `recurrence`, `anchor` — matching what `RescheduleActionRequest` reads. The markup mirrors the add form above it deliberately, so the two ways of describing a schedule cannot drift apart:

```tsx
/**
 * Amending one action: what it says, and when it happens.
 *
 * The schedule half posts on every save, even when only the title changed —
 * `RescheduleAction` treats a schedule that resolves to the action's current
 * one as no reschedule at all, so the occasions survive. That guard is on the
 * server rather than here on purpose: a diff computed in the client would
 * delete occasions the day it got the comparison wrong.
 *
 * Field names are unprefixed to match `actions.update`. `StartExperimentForm`
 * asks the same questions under `action_*` names because it posts them to the
 * experiment endpoint — its markup is worth copying, its names are not.
 */
function ActionEditor({
    action,
    onDone,
}: {
    action: ActionSummary;
    onDone: () => void;
}) {
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');

    return (
        <Form
            {...update.form(action.id)}
            options={{ preserveScroll: true, onSuccess: onDone }}
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1">
                        <label htmlFor={`action-title-${action.id}`} className="ds-label">
                            What to do
                        </label>
                        <input
                            id={`action-title-${action.id}`}
                            name="title"
                            defaultValue={action.title}
                            className={FIELD_CLASS}
                        />
                        {errors.title && (
                            <p className="text-sm text-destructive">{errors.title}</p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <label htmlFor={`action-kind-${action.id}`} className="ds-label">
                            When
                        </label>
                        <select
                            id={`action-kind-${action.id}`}
                            name="kind"
                            value={kind}
                            onChange={(e) =>
                                setKind(e.target.value as 'clock' | 'anchored')
                            }
                            className={FIELD_CLASS}
                        >
                            <option value="clock">At a time</option>
                            <option value="anchored">After something else</option>
                        </select>
                    </div>

                    {kind === 'clock' ? (
                        <div className="flex gap-3">
                            <div className="space-y-1">
                                <label htmlFor={`action-time-${action.id}`} className="ds-label">
                                    Time
                                </label>
                                <input
                                    id={`action-time-${action.id}`}
                                    name="time"
                                    type="time"
                                    className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                />
                                {errors.time && (
                                    <p className="text-sm text-destructive">{errors.time}</p>
                                )}
                            </div>
                            <div className="space-y-1">
                                <label
                                    htmlFor={`action-recurrence-${action.id}`}
                                    className="ds-label"
                                >
                                    How often
                                </label>
                                <select
                                    id={`action-recurrence-${action.id}`}
                                    name="recurrence"
                                    className="rounded-md border border-border bg-background px-3 py-2 text-sm"
                                >
                                    <option value="once">Once</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekdays">Weekdays</option>
                                    <option value="weekly">Weekly</option>
                                </select>
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-1">
                            <label htmlFor={`action-anchor-${action.id}`} className="ds-label">
                                After what
                            </label>
                            <input
                                id={`action-anchor-${action.id}`}
                                name="anchor"
                                className={FIELD_CLASS}
                            />
                            {errors.anchor && (
                                <p className="text-sm text-destructive">{errors.anchor}</p>
                            )}
                        </div>
                    )}

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
```

- [ ] **Step 4: Run the tests**

```bash
npx vitest run resources/js/pages/loops/show.test.tsx
npx tsc --noEmit
```

Expected: PASS. The existing action-layer tests — `lists a live action with its cadence in the action layer` and the routine-editor cases — must still pass; the read state's markup is unchanged apart from the added Edit button.

- [ ] **Step 5: Commit**

```bash
git add resources/js/patyourself/loops/action-layer.tsx resources/js/pages/loops/show.test.tsx
git commit -m "feat(loops): edit an action's wording and cadence in the action layer"
```

---

### Task 5: Editing a routine row's targets

**Files:**
- Modify: `resources/js/patyourself/training/routine-editor.tsx`
- Test: `resources/js/patyourself/training/routine-editor.test.tsx`

**Interfaces:**
- Consumes: `actions.exercises.update` from Task 3, via the Wayfinder helper. `routine-editor.tsx` already imports `routine from '@/routes/actions/exercises'`; the new helper is `routine.update`.

- [ ] **Step 1: Write the failing test**

Add to `resources/js/patyourself/training/routine-editor.test.tsx`, reusing its existing render helper and row fixture:

```tsx
    /**
     * Changing 3 x 10 to 4 x 8 used to mean removing the exercise and adding it
     * back, which sent it to the end of the routine. The edit is in place.
     *
     * Tap to edit rather than live inputs: the targets sit beside the reorder
     * arrows on a narrow screen, and a mis-tap must not change what the routine
     * asks for.
     *
     * Killing mutation: render the number inputs unconditionally. The first
     * assertion fails.
     */
    it('edits a row’s targets only after the target is pressed', async () => {
        const user = userEvent.setup();

        render(
            <RoutineEditor
                actionId={4}
                rows={[
                    {
                        id: 9,
                        exercise_id: 2,
                        exercise_name: 'Barbell Incline Bench',
                        position: 1,
                        target_sets: 3,
                        target_reps: 10,
                    },
                ]}
            />,
        );

        expect(screen.queryByLabelText(/sets/i)).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: /edit targets for barbell incline bench/i }),
        );

        expect(screen.getByLabelText(/sets/i)).toHaveValue(3);
        expect(screen.getByLabelText(/reps/i)).toHaveValue(10);
    });

    it('closes the target editor on cancel', async () => {
        const user = userEvent.setup();

        render(
            <RoutineEditor
                actionId={4}
                rows={[
                    {
                        id: 9,
                        exercise_id: 2,
                        exercise_name: 'Barbell Incline Bench',
                        position: 1,
                        target_sets: 3,
                        target_reps: 10,
                    },
                ]}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: /edit targets for barbell incline bench/i }),
        );
        await user.click(screen.getByRole('button', { name: /cancel/i }));

        expect(screen.queryByLabelText(/sets/i)).not.toBeInTheDocument();
        expect(screen.getByTestId('routine-row-target-9')).toHaveTextContent('3 x 10');
    });
```

- [ ] **Step 2: Run and watch it fail**

Run: `npx vitest run resources/js/patyourself/training/routine-editor.test.tsx -t "targets"`
Expected: FAIL — no button matching that label.

- [ ] **Step 3: Add the row edit state**

In `RoutineRow`, add local state and swap the target span for a button plus a conditional form. Replace the `<span data-testid={...routine-row-target...}>` block (currently lines 154–159) with:

```tsx
            {editingTargets ? (
                <Form
                    {...routine.update.form([actionId, row.id])}
                    options={{
                        preserveScroll: true,
                        onSuccess: () => setEditingTargets(false),
                    }}
                    className="flex shrink-0 items-center gap-1"
                >
                    {({ processing }) => (
                        <>
                            <label
                                htmlFor={`target-sets-${row.id}`}
                                className="sr-only"
                            >
                                Sets
                            </label>
                            <input
                                id={`target-sets-${row.id}`}
                                name="target_sets"
                                type="number"
                                min={1}
                                defaultValue={row.target_sets}
                                className="w-14 rounded-md border border-border bg-background px-1.5 py-0.5 text-xs"
                            />
                            <span aria-hidden="true" className="text-xs text-muted-foreground">
                                x
                            </span>
                            <label
                                htmlFor={`target-reps-${row.id}`}
                                className="sr-only"
                            >
                                Reps
                            </label>
                            <input
                                id={`target-reps-${row.id}`}
                                name="target_reps"
                                type="number"
                                min={1}
                                defaultValue={row.target_reps}
                                className="w-14 rounded-md border border-border bg-background px-1.5 py-0.5 text-xs"
                            />
                            <Button type="submit" variant="ghost" size="sm" disabled={processing}>
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditingTargets(false)}
                            >
                                Cancel
                            </Button>
                        </>
                    )}
                </Form>
            ) : (
                <button
                    type="button"
                    data-testid={`routine-row-target-${row.id}`}
                    aria-label={`Edit targets for ${row.exercise_name ?? 'this exercise'}`}
                    onClick={() => setEditingTargets(true)}
                    className="shrink-0 font-mono text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    {row.target_sets} x {row.target_reps}
                </button>
            )}
```

Add the state at the top of `RoutineRow`:

```tsx
    // Scoped to this row, unlike `reordering`: an edit posts only this row's
    // two columns, so a second row's edit cannot invalidate it — the cross-row
    // race that forces `reordering` up to the editor does not exist here.
    const [editingTargets, setEditingTargets] = useState(false);
```

Ensure `useState` is imported in this file (it already is, for `AddExercise`).

- [ ] **Step 4: Run the tests**

```bash
npx vitest run resources/js/patyourself/training/routine-editor.test.tsx
npx tsc --noEmit
```

Expected: PASS. **Watch the existing test that asserts on `routine-row-target-9`** — the element is now a `<button>` rather than a `<span>`, and its text content is unchanged, so a `toHaveTextContent` assertion still passes while a tag-name assertion would not. If one fails, report which.

- [ ] **Step 5: Full verification**

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Expected: PHP above 1015/6452, JS above 486, 0 TypeScript errors, pint and eslint clean.

- [ ] **Step 6: Commit**

```bash
git add resources/js/patyourself/training/routine-editor.tsx resources/js/patyourself/training/routine-editor.test.tsx
git commit -m "feat(gym): edit a routine row's targets without losing its place"
```

---

## Self-review

**Spec coverage.** §2's two-writer split → Task 2. §3's unchanged-schedule guard → Task 1. §4's writer, route and gating → Task 3. §5's tap-to-edit idiom → Tasks 4 and 5, and §5's field-name trap is carried into Task 4 Step 3. §6's file list → all five tasks; the doc rows → Task 3 Step 8. §7's test list → each task's own steps. §8's exclusions are not built, and the MCP one is recorded in Task 3 Step 8.

**One thing the spec says that this plan improves on.** §5 says to copy `StartExperimentForm`'s markup but not its field names. While planning I found `ActionLayer`'s own "Add an action" form asks the same questions with the names `actions.update` already expects — so Task 4 copies the form in the same file rather than one across the codebase, which removes the renaming step the trap warned about. The spec's warning stays true for anyone reaching for `StartExperimentForm`.

**Placeholder scan.** No TBD, no "add validation", no "similar to Task N" — every code step carries its code.

**Type consistency.** `UpdateRoutineExercise::handle(ActionExercise, int, int): ActionExercise` is used with that signature in Task 3's controller. `ActionSummary` is the existing exported type, used unchanged by `ActionEditor`. The route names `actions.update` and `actions.exercises.update` are used identically in the PHP tests, the route file and both Wayfinder call sites. `editing` (number | null, in `ActionLayer`) and `editingTargets` (boolean, in `RoutineRow`) are distinct pieces of state in different components.
