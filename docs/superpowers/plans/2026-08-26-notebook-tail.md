# The notebook tail — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish build steps 5–8 of the check-in brief — the action-layer write tools, `update-loop`, notes, and the three screens that make the record legible outside the chat.

**Architecture:** The MCP tools land first (thin, independently useful, and two screens render what they write), then the screens. Every write goes through an Action in `app/Actions`, so the MCP and web surfaces cannot drift. Nothing is deleted anywhere: `remove-action` archives, versions are appended, notes are append-only.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, `laravel/mcp` v0, Inertia v3 + React 19, Tailwind v4, Vitest.

Spec: `docs/superpowers/specs/2026-08-26-notebook-tail-design.md`

## Global Constraints

- **Reasons verbatim.** No trimming, squishing or sentence-casing in any path, UI included.
- **Strategy versions are append-only.** `remove-action` archives; nothing here deletes evidence.
- **No quantities on eating loops.** No calories, portions, weights or numeric targets in any field or copy.
- **Failure language is about the strategy, never the user.** Applies to field names, enum values, button labels, empty states and error messages.
- **No gamification.** No badges, levels or celebratory states. A streak is a statistic.
- **The notebook never nags.** No overdue counts, no red backlog states, no "you missed N".
- `planned_days: null` means open-ended and must never render as a countdown or a zero-day experiment.
- Counts render as raw fractions (`8/11`), never a rounded rate, at these sample sizes.
- Run `vendor/bin/pint --dirty --format agent` before every commit; `npm run lint` / `npx prettier --write` for touched frontend files.
- Tests: `php artisan test --compact --filter=<name>`; frontend `npx vitest run <path>`.
- Artisan: always `--no-interaction`.

---

### Task 1: `CreateAction` and `ArchiveAction`

**Files:**
- Create: `app/Actions/CreateAction.php`
- Create: `app/Actions/ArchiveAction.php`
- Test: `tests/Feature/Actions/CreateActionTest.php`, `tests/Feature/Actions/ArchiveActionTest.php`

**Interfaces:**
- Produces: `CreateAction::handle(Intention $loop, AuthoredAction $authored): Action` — attaches to the loop's active strategy, computes `scheduled_for` via `Schedule::firstOccurrence()`, sets `series_started_at` to it, status `pending`, and mirrors `PersistAuthoredIntention`'s metadata shape (`schedule_kind`, `anchor`).
- Produces: `ArchiveAction::handle(Action $action): Action` — sets `status = Action::STATUS_ARCHIVED`, returns it refreshed.

- [ ] **Step 1: Write the failing tests.** `CreateActionTest` covers: a clock action gets a future `scheduled_for` with `series_started_at` equal to it; an anchored action gets null for both and records its `anchor` in metadata; the action binds to the loop's **active** strategy; a loop with no active strategy throws. `ArchiveActionTest` covers: status becomes archived; the action's occurrences and logs survive untouched (assert counts before/after) — that is the whole reason it archives.
- [ ] **Step 2: Run them, watch them fail** — `php artisan test --compact --filter="CreateActionTest|ArchiveActionTest"`.
- [ ] **Step 3: Implement both**, copying `PersistAuthoredIntention::persistAction()`'s body for the schedule/metadata computation rather than inventing a second one.
- [ ] **Step 4: Run the tests**, expect PASS.
- [ ] **Step 5: Commit** — `feat(actions): add the create and archive action writers`.

---

### Task 2: `add-action`, `update-action`, `remove-action`

**Files:**
- Create: `app/Mcp/Tools/AddActionTool.php`, `UpdateActionTool.php`, `RemoveActionTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php`, `tests/Feature/Mcp/McpEndpointTest.php`
- Test: `tests/Feature/Mcp/ActionCrudToolsTest.php`

**Interfaces:**
- `add-action(intention_id, title, description?, kind, time?, recurrence?, anchor?)` → `{action_id, loop_id, title, kind, scheduled_for, recurrence, anchor, strategy_version}`
- `update-action(action_id, title?, description?, kind?, time?, recurrence?, anchor?)` → same shape
- `remove-action(action_id)` → `{action_id, status, occurrences_kept}`

Validation at the boundary, mirroring `AuthoredAction`: `kind` in `clock|anchored`; `time` matching `/^([01]\d|2[0-3]):[0-5]\d$/` and required when kind is clock; `recurrence` in `once|daily|weekdays|weekly`; `anchor` required when kind is anchored.

- [ ] **Step 1: Write the failing test.** Cases: adding a clock action anchors its series; adding an anchored action stores its phrase; `update-action` retitles without touching the anchor; changing the schedule re-anchors but keeps already-materialised occurrences; `remove-action` archives and keeps occurrences and logs; a rejected `kind`/`time`/`recurrence` writes nothing; each tool refuses another user's row with `Not found.`.
- [ ] **Step 2: Run it, watch it fail.**
- [ ] **Step 3: Write the three tools.** `update-action` routes schedule changes through `RescheduleAction` (it already re-anchors) and title/description through a direct `update()`; `remove-action` uses `ArchiveAction`. `remove-action`'s `#[Description]` must say it archives and keeps the history, so the coach never calls it a deletion to the user.
- [ ] **Step 4: Register all three** in `PatYourSelfServer::$tools`, add their names to the `McpEndpointTest` list, and name them in the server `#[Instructions]` (the endpoint test asserts every advertised name appears there).
- [ ] **Step 5: Run** `--filter="ActionCrudToolsTest|McpEndpointTest"`, expect PASS.
- [ ] **Step 6: Commit** — `feat(mcp): action CRUD unfreezes a loop's action layer`.

---

### Task 3: `update-loop`

**Files:**
- Create: `app/Mcp/Tools/UpdateLoopTool.php`
- Modify: `app/Mcp/Servers/PatYourSelfServer.php`, `tests/Feature/Mcp/McpEndpointTest.php`
- Test: `tests/Feature/Mcp/UpdateLoopToolTest.php`

**Interfaces:**
- `update-loop(intention_id, title?, description?, cue?, craving?, response?, reward?, status?)` → the `get-loop` chain block plus `status`.
- `status` restricted to `active|paused|archived` — **not** `completed`.

- [ ] **Step 1: Write the failing test.** Cases: correcting the craving persists it; a partial call leaves untouched fields alone; `status: active` on a paused loop re-anchors stale pending actions (assert a past `scheduled_for` moved to the future — the behaviour `UpdateIntention` already owns); `completed` is rejected; a call with no updatable field is rejected; another user's loop gives `Not found.`
- [ ] **Step 2: Run it, watch it fail.**
- [ ] **Step 3: Write the tool**, routing through `UpdateIntention` so the re-anchoring is not reimplemented. Description should say the chain as first written is a hypothesis and the craving is the field most often wrong.
- [ ] **Step 4: Register + endpoint test + instructions.**
- [ ] **Step 5: Run** `--filter="UpdateLoopToolTest|McpEndpointTest"`, expect PASS.
- [ ] **Step 6: Commit** — `feat(mcp): update-loop lets the chain be corrected`.

---

### Task 4: Notes — table, model, `log-note`, and reading them back

**Files:**
- Create: `database/migrations/<ts>_create_notes_table.php`, `app/Models/Note.php`, `database/factories/NoteFactory.php`
- Create: `app/Actions/LogNote.php`, `app/Mcp/Tools/LogNoteTool.php`
- Modify: `app/Models/Intention.php` (`notes()`), `app/Mcp/Tools/GetLoopTool.php`, `app/Mcp/Servers/PatYourSelfServer.php`, `tests/Feature/Mcp/McpEndpointTest.php`, `tests/Feature/Mcp/GetLoopToolTest.php`
- Test: `tests/Feature/Mcp/LogNoteToolTest.php`

**Interfaces:**
- `notes`: `id`, `intention_id` (FK cascade), `body` (text), `noted_at` (timestamp), timestamps. Index `['intention_id', 'noted_at']`.
- `Note::$body`, `$noted_at` (`immutable_datetime`), `intention(): BelongsTo`.
- `Intention::notes(): HasMany` ordered newest first.
- `LogNote::handle(Intention $loop, string $body, ?CarbonInterface $notedAt = null): Note`
- `log-note(intention_id, note, noted_at?)` → `{note_id, loop_id, body, noted_at}`
- `get-loop` gains `notes: [{id, body, noted_at}]`, newest first, capped at 50.

- [ ] **Step 1: Write the failing tests.** `LogNoteToolTest`: a note persists verbatim (same shouting-whitespace assertion used for reasons); an empty note is rejected; `noted_at` defaults to now and is accepted when given; another user's loop gives `Not found.`. In `GetLoopToolTest`: a logged note comes back in the `notes` block, newest first — **a note that cannot be read back is the exact bug this phase exists to fix.**
- [ ] **Step 2: Run them, watch them fail.**
- [ ] **Step 3: Build the migration, model, factory, Action and tool.** The migration docblock records why this is not `summaries`: a summary is a single rolling narrative that `progress/show` renders as one block, and appending discrete observations would turn it into an accidental log.
- [ ] **Step 4: Add the `notes` block to `GetLoopTool`** and register the tool + endpoint test + instructions.
- [ ] **Step 5: Run** `--filter="LogNoteToolTest|GetLoopToolTest|McpEndpointTest"`, expect PASS.
- [ ] **Step 6: Commit** — `feat(mcp): log-note, and read the notes back`.

---

### Task 5: The strategy timeline shows the experiment

**Files:**
- Modify: `resources/js/patyourself/strategy-timeline.tsx`, `resources/js/patyourself/types.ts`
- Modify: `app/Http/Resources/StrategyResource.php` (add `outcomes_recorded`), `app/Http/Controllers/IntentionController.php`
- Test: `resources/js/patyourself/strategy-timeline.test.tsx`, `tests/Feature/IntentionScreensTest.php`

**Interfaces:**
- `StrategyResource` gains `outcomes_recorded: int`, populated from a per-version count the controller passes in (`StrategyResource::withOutcomeCounts(Collection $counts)` or a `loadCount`-style precomputation — pick one and use it in both controllers that render the timeline).
- `StrategyData` gains `outcomes_recorded: number`.

- [ ] **Step 1: Write the failing tests.** Vitest: a running open-ended experiment renders `day 3` and **no** "of" and no countdown; one with `planned_days: 21` renders `day 3 of 21`; a concluded version renders its verdict and note; `outcomes_recorded: 0` renders as "not yet tested" rather than an empty gap; a version with outcomes renders the count. PHPUnit: `loops/show` props carry `outcomes_recorded` per strategy.
- [ ] **Step 2: Run them, watch them fail.**
- [ ] **Step 3: Implement.** Keep the existing node layout; add a metadata line beneath the approach. Verdict copy must stay strategy-facing: "worked" / "did not hold" / "inconclusive" — never "you failed".
- [ ] **Step 4: Run** vitest + `--filter=IntentionScreensTest`, expect PASS.
- [ ] **Step 5: Commit** — `feat(notebook): the timeline shows each experiment's shape and evidence`.

---

### Task 6: Outcome history on the loop record

**Files:**
- Create: `resources/js/patyourself/outcome-history.tsx` + `.test.tsx`
- Modify: `app/Http/Controllers/IntentionController.php`, `resources/js/pages/loops/show.tsx`, `resources/js/patyourself/types.ts`
- Test: `tests/Feature/IntentionScreensTest.php`

**Interfaces:**
- `IntentionController::show` gains an `outcomes` prop: `[{id, occurred_at, logged_at, action_title, outcome, reason, context, context_fields, strategy_version}]`, newest occasion first, 30 by default, all when `?history=all`, plus `outcomes_total: int`.
- `OutcomeEntryData` in `types.ts`; `<OutcomeHistory outcomes total showingAll loopId />`.

- [ ] **Step 1: Write the failing tests.** Vitest: a failure renders its reason verbatim (assert the exact string, whitespace and casing included); a skip renders neutrally and is **not** styled as a failure; an entry shows the version that was running; the empty state says logging has not started, with no exhortation. PHPUnit: `show` returns 30 by default and everything with `?history=all`; `outcomes_total` counts all.
- [ ] **Step 2: Run them, watch them fail.**
- [ ] **Step 3: Implement**, reusing the `occurredAt` fallback rule from `LoopOutcomesTool` (a pre-occurrence log falls back to `logged_at`).
- [ ] **Step 4: Run** vitest + `--filter=IntentionScreensTest`, expect PASS.
- [ ] **Step 5: Commit** — `feat(notebook): the loop record shows its outcome history`.

---

### Task 7: Notes on the loop record

**Files:**
- Modify: `app/Http/Controllers/IntentionController.php`, `resources/js/pages/loops/show.tsx`, `resources/js/patyourself/types.ts`
- Create: `resources/js/patyourself/loop-notes.tsx` + `.test.tsx`
- Test: `tests/Feature/IntentionScreensTest.php`

- [ ] **Step 1: Write the failing tests.** Vitest: notes render newest first with their date, body verbatim; empty state is a plain sentence. PHPUnit: the `notes` prop is present and ordered.
- [ ] **Step 2: Run them, watch them fail.**
- [ ] **Step 3: Implement.** Read-only on this screen — notes are written from the conversation.
- [ ] **Step 4: Run** vitest + `--filter=IntentionScreensTest`, expect PASS.
- [ ] **Step 5: Commit** — `feat(notebook): the loop record shows its notes`.

---

### Task 8: The in-app catch-up list

**Files:**
- Create: `app/Http/Controllers/CatchUpController.php`, `app/Http/Controllers/OccurrenceLogController.php`, `app/Http/Requests/LogOccurrenceRequest.php`, `app/Policies/OccurrencePolicy.php`
- Create: `resources/js/pages/catch-up.tsx` + `resources/js/pages/catch-up.test.tsx`
- Modify: `routes/web.php`, `resources/js/pages/loops/index.tsx`, `resources/js/patyourself/types.ts`
- Test: `tests/Feature/CatchUpScreenTest.php`

**Interfaces:**
- `GET /catch-up` → `catch-up` page, props `{occurrences: [{id, loop_id, loop_title, action_id, action_title, scheduled_for}], since, showing_all}`. Materialises via `MaterialiseOccurrences::forUser()` before reading; 14-day default; `?since=all` reaches the whole backlog.
- `POST /occurrences/{occurrence}/logs` → `LogAction::handle($user, $occurrence->action, $validated, $occurrence)`, gated by `OccurrencePolicy::log`, `reason` required on `failed`, returns `back()`.

- [ ] **Step 1: Write the failing tests.** PHPUnit: the screen materialises before rendering, so a never-logged action appears; it omits logged, future, paused-loop and other users' occasions; the 14-day default and `?since=all`; logging an occasion attaches the outcome to *it* and does not move the action's next-due pointer; a failure with no reason is rejected; another user's occurrence is forbidden. Vitest: rows group by loop; the reason field appears only for a failure; the empty state is plain, with no congratulation.
- [ ] **Step 2: Run them, watch them fail.**
- [ ] **Step 3: Implement** the controllers, request, policy, route entries and page. Link it from the loops index header as plain text ("Catch up") — **no count, no badge, no red state**, and not a primary nav tab.
- [ ] **Step 4: Run** `--filter=CatchUpScreenTest` + vitest, expect PASS.
- [ ] **Step 5: Commit** — `feat(notebook): catch up on unlogged occasions in the app`.

---

### Task 9: Whole-surface verification

- [ ] **Step 1:** `php artisan test --compact` — expect all green.
- [ ] **Step 2:** `npx vitest run` — expect all green.
- [ ] **Step 3:** `npm run build` — the new pages must compile; a Vite manifest miss breaks every screen test.
- [ ] **Step 4:** `npm run lint` and `npx prettier --check` on touched frontend files.
- [ ] **Step 5:** Run the suite against MySQL 8 as well as SQLite — the last phase found a rollback bug SQLite hid, and Task 4 adds a migration.
- [ ] **Step 6:** Append carry-forward notes to this plan, then commit.

## Self-review notes

- **Spec coverage.** Step 5 → Tasks 5 and 6; step 6 → Tasks 1, 2, 3; step 7 → Task 8; step 8 → Tasks 4 and 7.
- **Deliberately not covered** (spec "Out of scope"): `conclude-experiment`, `write-reflection`, MCP prompts, `/log` fast-capture, the `/loops` index redesign, the dashboard reframe.
- **Type consistency.** `outcomes_recorded` is the same key in `StrategyResource`, `StrategyData` and `get-loop`. `occurred_at`/`logged_at` keep the meanings `LoopOutcomesTool` gave them. `LogAction::handle`'s fourth parameter stays `?Occurrence`.
