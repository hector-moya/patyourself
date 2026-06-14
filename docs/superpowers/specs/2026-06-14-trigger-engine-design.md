# SP2 — Trigger Engine (design)

**Date:** 2026-06-14
**Status:** Approved, ready for implementation planning
**Program slice:** SP2 of the "close the habit loop" decomposition (see SP1 spec,
`docs/superpowers/specs/2026-06-13-action-authoring-design.md`)

---

## App intent (one paragraph)

PatYourSelf is a conversational habit-change coach. It models every habit as an
_Atomic Habits_ loop — cue → craving → response → reward — and treats each attempt
as a CBT-style behavioural experiment. SP1 closed the first missing link: the
Coach now authors a concrete, scheduled (or anchored) `Action` bound to each
Strategy, and the user can edit its time/recurrence. **SP1 deliberately fires
nothing.** The whole point of a habit app is to prompt the right behaviour at the
right moment — SP2 builds the engine that does the prompting.

## The problem this slice solves

SP1 stores a schedule (`actions.scheduled_for` UTC + `actions.recurrence`) and
leaves a pure, tested `App\Services\Scheduling\Schedule` with an `advance()`
method that has **zero callers**. Nothing scans for due actions, nothing
transitions them, nothing rolls a recurring action to its next occurrence. New
actions are authored `pending` and sit there forever.

SP2 fixes the **second missing link**: a scheduler that scans due actions
(`scheduled_for <= now`, status `pending`), **fires** them (transition
`pending → active` so they surface as live to-dos), and rolls recurring actions
forward to their next occurrence. SP2 is the **engine only** — rich notification
delivery (web push / email / in-app inbox) is SP3; auto-revision / summary on
failure is SP4. Neither may creep in.

---

## Decisions (locked in brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Recurrence row model | **Roll forward in place** — one Action row per recurring commitment, carrying the next `scheduled_for`. No new row per occurrence. |
| 2 | How the engine runs | **Laravel scheduler `everyMinute()` → `actions:fire` command → pure `TriggerEngine` service.** Fires inline (a cheap DB state transition); no queue. |
| 3 | Idempotency / locking | **Guarded atomic flip** (`UPDATE … WHERE id=? AND status='pending'`, owner = the run whose update affects exactly 1 row) **+ `withoutOverlapping()`** on the schedule. |
| 4 | Catch-up after downtime | **Fire once + fast-forward.** A stale pending action fires exactly once now; on resolution, advancing skips all missed slots to the next occurrence strictly after now. No backfill. |
| 5 | DST / timezone at fire | **Wall-clock local time anchor.** A 07:00 action stays 07:00 local across DST; the UTC instant shifts. Store/compare UTC; user tz only localises the math (exactly what `Schedule::advance()` already does). |

### Why roll-forward-in-place over new-row-per-occurrence

The alternative (engine spawns a fresh `pending` row at fire time) keeps recurrence
entirely inside the scheduler and gives one row per occurrence — but it would force
a rework of SP1's `Intention::activeAction()` (currently
`latestOfMany()` over `pending+active`) and the "exactly one active Action per
active Strategy" invariant, plus auto-skip machinery to stop unlogged occurrences
piling up as multiple `active` rows, plus catch-up fast-forward to avoid fire-storms.
Roll-forward keeps SP1's invariant, relation, and schema untouched; per-occurrence
history already lives in `action_logs.logged_at` (the column SP1 documented as "may
differ from created_at"). The single cost — recurrence advance lives in the log
resolution path — is a small, deterministic, engine-owned state transition, not
SP4's LLM coaching logic.

---

## Design

### 1. Components

| Unit | Type | Responsibility | Depends on |
|---|---|---|---|
| `App\Services\Scheduling\TriggerEngine` | service (new) | Scan due actions; fire each (`pending → active`) idempotently; record `fired_at`. Returns the count fired. **The engine.** | `Action`, DB |
| `App\Services\Scheduling\Schedule::nextAfter()` | pure method (new, on existing VO) | Fast-forward: repeatedly `advance()` from a base time until strictly after `now`. Returns the next UTC occurrence, or null for a one-off. | `Recurrence`, Carbon |
| `App\Console\Commands\FireDueActions` (`actions:fire`) | command (new) | Thin wrapper that resolves `TriggerEngine` and calls it; reports the count. | `TriggerEngine` |
| `routes/console.php` | wiring | `Schedule::command('actions:fire')->everyMinute()->withoutOverlapping();` | — |
| `App\Actions\LogAction` | action (modify) | After the existing status transition, **re-arm** a closed recurring action to its next occurrence. | `Schedule`, `Recurrence` |
| `resources/js/.../action-card.tsx` | UI (modify, minimal) | Render a "Due now" indicator when the active action's `status === 'active'`. No new endpoint, no polling. | existing `active_action.status` prop |

Each unit is testable in isolation: `Schedule::nextAfter` is pure math;
`TriggerEngine` is exercised by calling it directly (no cron); `LogAction`'s
re-arm is exercised through the existing log flow.

### 2. Engine behavior (`TriggerEngine::fireDueActions`)

**Selection query** (drives off the existing `scheduled_for` index):

```
status = 'pending'
AND scheduled_for IS NOT NULL
AND scheduled_for <= now()            -- UTC comparison
AND intention is active               -- whereHas: skip paused / archived loops
```

Anchored actions (`scheduled_for IS NULL`) are never selected, so the engine never
fires them. Archived / completed / skipped actions are excluded by the status
filter. A `pending` action is, by SP1's invariant, the live one for its active
strategy, so no extra strategy guard is needed.

**Firing** — per selected row, a guarded atomic update:

```sql
UPDATE actions
   SET status = 'active', metadata = <metadata + fired_at>
 WHERE id = ? AND status = 'pending'
```

- The run whose update affects **exactly 1 row** "owns" that fire and stamps
  `metadata.fired_at` (a UTC timestamp; useful for SP3 dedupe and for debugging).
- A concurrent or duplicate run's update affects **0 rows** → no-op. This atomic
  guard is the idempotency key.
- `withoutOverlapping()` on the schedule is the outer guard against two runs
  starting at once.

**Firing does NOT touch `scheduled_for`.** Advance happens at resolution (re-arm),
never at fire. A fired action is `active` with its `scheduled_for` still pointing at
the occurrence that just came due (the card reads "due now").

### 3. Re-arm — recurrence roll-forward (`LogAction`)

`LogAction::handle` today: records the `ActionLog`, then transitions the action
(`completed → completed`, `skipped → skipped`, **`failed → unchanged`**). SP2 adds,
inside the same transaction, after the transition:

| Action shape | Outcome | Result |
|---|---|---|
| recurring (`recurrence` set) | completed **or** skipped | `scheduled_for = Schedule::nextAfter(scheduled_for, now, recurrence, user.tz)`; `status = pending`. The one row rolls forward; the occurrence's outcome is preserved in `action_logs.logged_at`. |
| recurring | failed | unchanged — stays open to retry (existing behaviour; SP4's revise supersedes later). |
| one-off (`recurrence` null, `scheduled_for` set) | any | unchanged — closed forever. |
| anchored (`scheduled_for` null, `recurrence` null) | any | unchanged — closed. |

Re-arm uses the user's IANA tz (`user.timezone ?? config('app.timezone')`).
`LogAction` gains a dependency on `Schedule` — consistent with its docblock, which
forbids *LLM* side-effects (revise/summary), not deterministic state transitions.

**Known limitation (out of SP2 scope):** an anchored daily habit ("10 push-ups
after coffee") does not re-arm, because SP1 stores no recurrence for anchored
actions (`recurrence` is null). Fixing this needs an SP1 model change (recurrence
on anchored actions) and is left for future work.

### 4. `Schedule::nextAfter`

```php
public function nextAfter(
    CarbonImmutable $from,
    CarbonImmutable $now,
    ?Recurrence $recurrence,
    string $timezone,
): ?CarbonImmutable
```

Repeatedly applies the existing `advance()` starting at `$from` until the result is
**strictly after** `$now`; returns that UTC instant, or null when `$recurrence` is
null (one-off — caller never re-arms these). Because `advance()` does local-space
math (Daily +1 day, Weekdays +1 day skipping weekends, **Weekly +7 preserving the
weekday**), `nextAfter`:

- **handles catch-up** — a base several periods stale loops forward to the first
  future slot (daily down 3 days → 4 steps; weekly is usually 1 step);
- **handles DST** — each step is wall-clock-preserving in the user's tz;
- **preserves weekly's anchor day** — Weekly stays the same weekday (unlike
  `firstOccurrence`, which is "next day at HH:MM" start semantics and is therefore
  not reused here).

Normal (non-stale) case: `from` is the occurrence that just fired (≈ now), one
`advance()` step yields the next occurrence — one iteration.

### 5. Scheduling wiring

`routes/console.php` (Laravel 11+ schedule-in-routes style):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('actions:fire')->everyMinute()->withoutOverlapping();
```

Locally the app is served by Herd; `schedule:run` is not wired to system cron, so
in dev the engine is exercised by running `php artisan actions:fire` (or directly in
tests). Production (Laravel Cloud) runs the scheduler. No queue worker is required —
firing is synchronous.

### 6. Frontend (minimal surfacing)

The action card already renders `active_action` including its `status` and
`scheduled_for` (SP1). SP2 adds a single visual state: when
`active_action.status === 'active'`, show a small "Due now" badge to distinguish a
fired/live to-do from a still-scheduled (`pending`) one. No new endpoint, no
polling, no live refresh — the badge appears on the next Inertia page load. Rich
delivery (push/email/inbox, real-time surfacing) is SP3.

---

## Testing

**Unit — `Schedule::nextAfter` (`tests/Unit/Scheduling/ScheduleTest.php`, extend):**
- Daily / Weekdays / Weekly fast-forward from a stale base to the first future slot.
- Weekly preserves the weekday across the jump.
- Normal case = one step (`from ≈ now` → next occurrence).
- One-off (`recurrence` null) → null.
- DST regressions: 07:00 daily across US spring-forward and fall-back (UTC instant
  shifts an hour, local time holds); spring-forward gap hour; fall-back ambiguous hour.

**Feature — `TriggerEngine` (`tests/Feature/Scheduling/TriggerEngineTest.php`):**
- Fires a due `pending` action in an active intention (`pending → active`,
  `fired_at` stamped).
- Does **not** fire: a future `pending`; an anchored action (`scheduled_for` null);
  an action whose intention is paused/archived; an already-`active` / `completed` /
  `archived` action.
- Idempotency: running the engine twice fires once (second run's guarded update
  no-ops).
- Catch-up: a stale (days-overdue) pending action fires exactly once, not N times.
- Returns the correct fired count.

**Feature — `actions:fire` command (`tests/Feature/Console/FireDueActionsCommandTest.php`):**
- Invokes the engine and fires due actions; exits 0; reports the count.

**Feature — `LogAction` re-arm (`tests/Feature/Actions/LogActionTest.php`, extend):**
- Recurring + completed → `status = pending`, `scheduled_for` advanced to the next
  future occurrence.
- Recurring + skipped → same re-arm.
- Recurring + failed → stays open (no re-arm).
- One-off + completed → closed (no re-arm).
- Anchored + completed → closed (no re-arm).
- Stale recurring + completed → re-armed to a future time (fast-forward, not past).

**Frontend — `action-card.test.tsx` (extend):**
- "Due now" badge shows when `status === 'active'`; absent when `pending`.

---

## Files touched (anticipated)

**New**
- `app/Services/Scheduling/TriggerEngine.php`
- `app/Console/Commands/FireDueActions.php`
- `tests/Feature/Scheduling/TriggerEngineTest.php`
- `tests/Feature/Console/FireDueActionsCommandTest.php`

**Modified**
- `app/Services/Scheduling/Schedule.php` — add `nextAfter()`.
- `app/Actions/LogAction.php` — re-arm recurring actions on close.
- `routes/console.php` — register the scheduled command.
- `tests/Unit/Scheduling/ScheduleTest.php` — `nextAfter` + DST tests.
- `tests/Feature/Actions/LogActionTest.php` — re-arm tests.
- `resources/js/patyourself/chat/action-card.tsx` — "Due now" badge.
- `resources/js/patyourself/chat/action-card.test.tsx` — badge test.

---

## Success criteria

1. A due `pending` action in an active intention transitions to `active` when the
   engine runs, surfacing as a live "due now" to-do on its card.
2. A recurring action, once its occurrence is completed or skipped, rolls forward to
   its next future occurrence (same row); a one-off closes; an anchored action is
   never fired.
3. Running the engine repeatedly (or with overlap) fires each occurrence **at most
   once** (guarded flip + `withoutOverlapping()`).
4. After downtime, a stale recurring action fires exactly once and fast-forwards
   past missed slots — no backfill, no fire-storm.
5. Schedules are DST-correct: a 07:00 daily action stays at 07:00 local across a DST
   boundary.
6. All new and affected tests pass; `vendor/bin/pint` clean.
7. Nothing beyond in-app state transitions happens — no notifications (SP3), no
   auto-revision/summary (SP4).

## Scope boundary — explicitly NOT in SP2

- No notification delivery — web push / email / in-app inbox (SP3).
- No auto-revision or rolling-summary wiring on failure (SP4).
- No anchored-recurrence support (needs an SP1 model change).
- No polling / real-time / live refresh of the card.
- No queue worker / per-action fan-out jobs (firing is a synchronous state flip).
- UI limited to a single "Due now" badge.
