# An amendable action layer

**Date:** 2026-09-13
**Status:** design agreed, not yet implemented
**Scope:** the action layer on the loop screen, its web endpoints, and one new writer in the gym module.

---

## 1. The problem

The action layer can add and remove. It cannot amend.

Observed on the loop screen: an action shows its title, its cadence and a `Retire`; each routine row shows its exercise, `3 × 10`, reorder arrows and a `Remove`. Nothing edits anything.

| Change | App | Web endpoint | MCP |
| --- | --- | --- | --- |
| Rename an action | no | no | **yes** — `update-action` |
| Edit its description | no | no | **yes** — `update-action` |
| Change cadence / time / anchor | no | **yes — exists, unused** | yes |
| Change a routine row's `3 × 10` | no | no | no |
| Reorder rows | yes | yes | no |
| Add / remove a row | yes | yes | yes |
| Retire an action | yes | yes | yes |

Three gaps of three different sizes:

**Cadence is built and unwired.** `PATCH actions/{action}` routes to `ActionController@update` → `RescheduleAction`, which handles re-anchoring and purges abandoned occurrences. Wayfinder has even generated the client helper. Nothing in `resources/js` calls either.

**Rename needs the endpoint widened.** `RescheduleActionRequest` validates only `kind`, `time`, `recurrence` and `anchor`. `UpdateActionTool` sidesteps this by calling `$action->update()` directly for the title and routing only the schedule through the shared writer — so the coach can rename an action and the owner cannot.

**Routine targets have no writer anywhere.** `app/Actions/Training/` holds Add, Remove, Reorder and RecordSet. Changing `3 × 10` to `4 × 8` means removing the exercise and adding it back, which also drops it to the end of the routine.

## 2. Rename must never reach the rescheduler

`RescheduleAction::handle()` rewrites `series_started_at` and calls
`ReanchorsSeries::purgeAbandonedOccurrences()`. Routing a rename through it would delete an action's future scheduled occasions for a text change.

So the server keeps two writers, and the endpoint decides between them — the shape `UpdateActionTool` already uses:

```
PATCH actions/{action}
  title?  description?     ->  $action->update(...)
  kind? time? recurrence? anchor?  ->  RescheduleAction::handle(...)   only when `kind` is present
```

Web and connector then amend an action through the same two writers, with the same rule about which one fires.

## 3. Rescheduling to an unchanged schedule is a no-op

This is the subtle requirement, and the one most likely to cause damage if skipped.

The action form carries title and schedule together behind one Save, so the schedule fields are always populated and will always be sent — including when the owner only fixed a typo in the title. Under §2's rule, `kind` is present, so `RescheduleAction` fires and purges future occasions on every rename.

**`RescheduleAction::handle()` returns the action untouched when the schedule it computes matches the schedule the action already has** — same resulting anchor, same recurrence, same `schedule_kind` and `anchor` metadata. No re-anchor, no purge.

The guard lives in the writer rather than in the client on purpose. A client that forgets to diff would silently delete occasions, and the connector reaches the same writer by a different route. One place, unbypassable, protecting both callers.

**Existing coverage is not threatened.** Every reschedule test changes the schedule — `test_rescheduling_to_a_new_time_re_anchors_the_series`, `test_turning_an_action_cue_anchored_clears_the_series_anchor`, `test_rescheduling_purges_unlogged_future_occasions` and the rest of `tests/Feature/Actions/SeriesAnchorTest.php`, plus `RescheduleActionWebTest` and `Api/ActionRescheduleTest`. None reschedules to an identical schedule, so none asserts a purge the guard would prevent. The guard needs its own test; it does not need any of them rewritten.

## 4. A writer for routine targets

`app/Actions/Training/UpdateRoutineExercise.php` — the fourth writer beside Add, Remove and Reorder, and the only place a routine row's targets change.

```php
handle(ActionExercise $actionExercise, int $targetSets, int $targetReps): ActionExercise
```

It sets the two columns and nothing else. **`position` is untouched**, which is the whole point: remove-and-re-add loses a row's place in the routine, and editing must not.

**Performed sets are untouched.** They key to occurrences, not to the routine, so changing a target never rewrites what was lifted. A routine that said 3 when four sets were recorded keeps both facts.

Route `PATCH actions/{action}/exercises/{actionExercise}`, beside the existing store / reorder / destroy. Gated on `ActionPolicy::update` like the rest of the routine surface — a routine is part of the standing prescription, not a fact about an occasion. `UpdateRoutineExerciseRequest` mirrors `StoreRoutineExerciseRequest`'s target rules: `['required', 'integer', 'min:1']` for both.

## 5. Tap to edit, explicit save

One idiom for both surfaces. A row is read-only until tapped, then becomes a small form with Save and Cancel. This matches the workflow picker and the add-exercise form, and it means a mis-tap on a phone cannot silently change a target.

```
READ
  Upper body 2                      Edit   Retire
   weekly
   Barbell Incline Bench   3 x 10   ↑ ↓    Remove

EDITING THE ACTION
  [ Upper body 2______________ ]
  ( ) At a time   [17:30]  [weekly ▾]
  (•) After a cue [ after work_____ ]
              Save    Cancel

EDITING A ROW
   Barbell Incline Bench   [3] x [10]   Save   Cancel
```

- The action header gains `Edit` beside `Retire`.
- The `3 × 10` becomes a button that swaps that row's targets for two number inputs.
- At most one thing is in edit state at a time; Cancel restores the read state without a request.
- The schedule control reuses the shape `StartExperimentForm` already uses — a `kind` select switching between a `time` input with a recurrence select and a free-text anchor — so the two surfaces do not disagree about how a schedule is described.

**One trap in that reuse.** `StartExperimentForm` names its fields `action_kind`, `action_time`, `action_recurrence` and `action_anchor`, because it posts them to the experiment endpoint alongside the strategy's own fields. The action edit form posts to `actions.update`, whose request reads them **unprefixed** — `kind`, `time`, `recurrence`, `anchor`. Copy the markup, not the names.

### Copy

`routine-editor.tsx` and `action-layer.tsx` are both on
`CompanionVocabularyTest::sourceFiles()`, so all new copy and comments avoid `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown` — `points` and `percent` are substring traps.

Sentence case, no exclamation marks. **Editing a target must not be framed against what was performed.** The routine is a note of intent; the edit form says what it will be, never how it compares to what happened.

## 6. Files

| File | Change |
| --- | --- |
| `app/Actions/Training/UpdateRoutineExercise.php` | **new** |
| `app/Http/Requests/Training/UpdateRoutineExerciseRequest.php` | **new** |
| `app/Actions/RescheduleAction.php` | the unchanged-schedule guard (§3) |
| `app/Http/Requests/RescheduleActionRequest.php` | `kind` becomes optional; `title` and `description` added |
| `app/Http/Controllers/ActionController.php` | `update()` applies title/description, reschedules only when `kind` is present |
| `app/Http/Controllers/Training/RoutineController.php` | `update()` action |
| `routes/web.php` | one route |
| `resources/js/patyourself/loops/action-layer.tsx` | the action edit state |
| `resources/js/patyourself/training/routine-editor.tsx` | the row edit state |
| `docs/GYM.md` | §10 loses "no way to edit a routine target"; §6 gains the fourth writer |
| `docs/MCP.md` | §11 notes the connector still cannot amend a routine target |

No migration. No model change. No new file on the vocabulary list — the two frontend files are already on it.

## 7. Testing

**New PHP:**
- `RescheduleAction` returns the action untouched, and purges nothing, when the schedule is unchanged — asserted against an unlogged future occasion that must survive.
- `ActionController@update` renames without touching `series_started_at` or occurrences.
- `ActionController@update` reschedules when `kind` changes, exactly as it does today.
- `ActionController@update` rejects an empty payload rather than treating it as a no-op success.
- `UpdateRoutineExercise` changes both targets and leaves `position` alone.
- The route is gated: a stranger gets a 403, an exercise outside the owner's catalogue is unreachable.
- Editing a target leaves existing `performed_sets` untouched.

**New JS:**
- The action header renders read-only until `Edit` is pressed.
- Cancel restores the read state and sends nothing.
- Saving with only the title changed still posts the schedule fields — the form always carries them — and the occasions survive, because §3's guard makes that reschedule a no-op. Assert the surviving occasion, not the absence of the fields.
- A row's targets edit in place and the row keeps its position in the list.

**Verification:**

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline to hold: 1015 PHP tests / 6452 assertions, 486 JS tests, 0 TypeScript errors. Both counts rise. `npm run build` must run first or `PwaManifestTest` skips itself and drops ~470 assertions.

## 8. Out of scope

- **No MCP tool for routine targets.** The connector can add and remove; a third tool is its own decision. Recorded in `docs/MCP.md` §11 rather than built.
- **No rep ranges.** `target_reps` stays a single integer, as `docs/GYM.md` §3 records.
- **No editing performed sets.** `RecordSet` remains the only writer of a `PerformedSet` and there is still no update path — a mistyped set stays mistyped.
- **No reordering from the connector.**
- Nothing on the dashboard, catch-up or session screens.

> **Correction (2026-09-13):** `description` is accepted by `RescheduleActionRequest` and written by `ActionController@update`, but no UI posts it — the action editor has no description field. This is deliberate, not an oversight: the endpoint mirrors `UpdateActionTool`'s shape, which already writes `description`, so the web endpoint and the connector accept the same fields even though only the connector's caller currently has a reason to send one. Covered directly in `RescheduleActionWebTest::test_owner_can_update_the_description`, since no app-level test exercises it otherwise.
