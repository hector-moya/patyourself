# SP4 — Auto-Coaching Closure (design)

**Date:** 2026-06-19
**Status:** Approved, ready for implementation planning
**Program slice:** SP4 of the "close the habit loop" decomposition (see SP1 spec,
`docs/superpowers/specs/2026-06-13-action-authoring-design.md`; SP2 spec,
`docs/superpowers/specs/2026-06-14-trigger-engine-design.md`; SP3 spec,
`docs/superpowers/specs/2026-06-15-cue-delivery-design.md`)

---

## App intent (one paragraph)

PatYourSelf is a conversational habit-change coach. It models every habit as an
_Atomic Habits_ loop — cue → craving → response → reward. SP1 authored a concrete,
scheduled `Action` per Strategy. SP2 fires a due action and rolls recurring actions
forward. SP3 delivers the fire as a persistent in-app cue and clears it when the
user logs the outcome. The loop is `CREATE → CUE → ACT → LOG → LEARN → (new
strategy) → …`. Every link is built **except the last**: nothing happens when the
user logs. The coaching primitives exist — `ReviseStrategy` (versioned
stack-on-success / restrategize-on-failure) and `UpdateRollingSummary` (rolling
pattern summary) — but `ReviseStrategy` only runs when a user hits an API endpoint
by hand, and `UpdateRollingSummary` is **never called from anywhere**. SP4 closes
the **fourth missing link**: it makes logging an outcome automatically run the
coaching pass.

## The problem this slice solves

`LogAction` is deliberately LLM-free (its docblock: "revising a strategy and
refolding a summary both make model calls, so they run as separate, explicit
steps"). Today nothing supplies those steps automatically: a user can fail the same
action ten times and the strategy never adapts; the rolling summary never updates
because `UpdateRollingSummary` has no caller. The "LEARN" half of the loop is dead
code waiting to be wired.

SP4 wires it. When a user logs an action, an after-commit queued job runs the
coaching closure: it always refolds the rolling summary, and on a deterministic
streak threshold it revises the strategy — surfacing the revision as an inbox cue
through SP3's existing notification plumbing. No new coaching logic is written; SP4
is the **closure wiring** around the primitives SP1–SP3 already shipped.

---

## Decisions (locked in brainstorming)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Autonomy | **Fully automatic.** On a qualifying signal the job revises the strategy outright (supersede active version, author a new action) — no propose/approve step. The user is told after the fact (Decision 5). |
| 2 | Revision trigger | **Threshold streaks.** Deterministic consecutive-outcome counts on the active strategy decide when to revise — not every log, not LLM-judged. Avoids per-occurrence churn; trivially testable. |
| 3 | Thresholds | **Fail fast / stack slow: 2 & 5.** `2` consecutive `failed` → `restrategizeOnFailure`; `5` consecutive `completed` → `stackOnSuccess`. `skipped` outcomes are ignored entirely. Both values are config — `services.coach.fail_streak` / `services.coach.stack_streak`, alongside the existing `services.coach.*` keys. |
| 4 | Summary cadence | **Every log.** The job always calls `UpdateRollingSummary`, which already returns `null` when there is nothing new to fold — safe to fire after each event. |
| 5 | User notification | **Inbox cue, reusing SP3.** A new `StrategyRevisedNotification` (`database` channel) lands in the existing `/inbox` and contributes to the unread badge. No new delivery plumbing. |
| 6 | Execution | **After-commit queued job.** `LogAction` dispatches an `ActionLogged` event (`ShouldDispatchAfterCommit`); a queued, auto-discovered listener runs the LLM-bearing pass off the request. Queue connection is the existing `database`. |

### Why an event + queued listener over calling the actions inline in `LogAction`

`LogAction` is intentionally synchronous, transactional, and LLM-free — the web and
API logging endpoints both depend on it returning fast with a saved `ActionLog`.
Two LLM calls (Strategist + Summarizer) inline would block the request, couple the
write path to model latency/cost, and break the action's documented contract. An
`ActionLogged` event mirrors SP2/SP3's `ActionFired` → `SendDueNotification`
pattern exactly: the write path emits a fact; a separate, queued listener reacts.
`ShouldDispatchAfterCommit` guarantees the event only fires once the log is durably
committed, so a rolled-back transaction never triggers coaching.

---

## Design

### 1. Components

| Unit | Type | Responsibility | Depends on |
|---|---|---|---|
| `App\Events\ActionLogged` | event (new) | Immutable carrier of the just-recorded log: `User`, `Action`, `ActionLog`. `implements ShouldDispatchAfterCommit`. Mirrors `ActionFired`. | `Action`, `ActionLog`, `User` |
| `App\Actions\LogAction` | action (modify) | After writing the log + close/re-arm + mark-cue-read (all existing), dispatch `ActionLogged`. One line; no other change. | `ActionLogged` |
| `App\Listeners\RunCoachingClosure` | listener (new) | On `ActionLogged`: update the rolling summary; compute the active strategy's streak; revise if a threshold is met; notify on revision. `implements ShouldQueue`, `$afterCommit = true`, `$tries = 3`, backoff. Auto-discovered. | `UpdateRollingSummary`, `ReviseStrategy`, `StrategyRevisedNotification` |
| `App\Notifications\StrategyRevisedNotification` | notification (new) | `via() = ['database']`; `toArray()` payload for the inbox row describing the revision. | `Strategy` |
| `config/services.php` | config (modify) | `coach.fail_streak` (2) and `coach.stack_streak` (5) under the existing `coach` array, env-overridable. | — |
| `App\Actions\ReviseStrategy` | action (reuse, unchanged) | `restrategizeOnFailure` / `stackOnSuccess` — already supersede + author. | — |
| `App\Actions\UpdateRollingSummary` | action (reuse, unchanged) | Refold rolling summary; returns `null` on no-op. | — |
| `resources/js/pages/inbox.tsx` | page (modify) | Render the new notification type's rows (a revision cue links to its loop). | shared props |

No model, migration, or schema change. SP4 reuses the `notifications` table from SP3
and the `strategies` / `action_logs` tables from SP1.

### 2. The closure path

`LogAction::handle` ends its transaction by dispatching the event (held until
commit by `ShouldDispatchAfterCommit`):

```php
// inside the existing DB::transaction closure, after markCueAnswered(...)
ActionLogged::dispatch($user, $action, $log);
```

`RunCoachingClosure` reacts off the queue:

```php
public function handle(ActionLogged $event): void
{
    $intention = $event->action->intention;

    // 1. Always refold the rolling summary (no-op when nothing new).
    Cache::lock("coaching:intention:{$intention->id}", 30)->block(5, function () use ($intention) {
        app(UpdateRollingSummary::class)->handle($intention);

        // 2. Decide + revise on a streak threshold.
        $active = $intention->activeStrategy;        // null-safe; skip if none
        if ($active === null) {
            return;
        }

        [$outcome, $run, $latestFailureReason] = $this->streak($active);

        try {
            if ($outcome === ActionLog::OUTCOME_FAILED && $run >= config('services.coach.fail_streak', 2)) {
                $new = app(ReviseStrategy::class)->restrategizeOnFailure($active, $latestFailureReason ?? '');
                $this->notifyRevised($intention->user, $new, $active);
            } elseif ($outcome === ActionLog::OUTCOME_COMPLETED && $run >= config('services.coach.stack_streak', 5)) {
                $new = app(ReviseStrategy::class)->stackOnSuccess($active);
                $this->notifyRevised($intention->user, $new, $active);
            }
        } catch (StrategyTransitionException) {
            // Already superseded by a concurrent run — benign.
        } catch (CoachQuotaException) {
            // Over budget — skip silently; the streak persists and retries on the next qualifying log.
        }
    });
}
```

The `Cache::lock` serializes coaching for one intention so a double-delivered job
never double-spends LLM tokens. (`block(5, …)` waits briefly; if it cannot acquire,
the job releases and retries — the work is idempotent.)

### 3. Streak logic (deterministic)

The **streak** is the leading run of one non-skip outcome on the **active
strategy's** action logs:

```
logs of the active strategy's actions, newest-first
  → drop every `skipped`
  → take the leading contiguous run of one outcome (failed | completed)
  → (outcome, run length, reason-of-the-newest-failed-in-run)
```

- Scope is the **active strategy** only: `ActionLog`s belonging to actions whose
  `strategy_id` is the active strategy's id. A revision supersedes that strategy and
  archives its actions while authoring a fresh one, so the **new** active strategy
  starts at streak 0 — the count resets automatically after every revision (no extra
  bookkeeping).
- `skipped` neither counts toward a run nor breaks it (it is removed before the run
  is measured).
- An opposite outcome breaks the run (a `completed` after `failed, failed` resets the
  failure run to 0; the next streak measured is the `completed` run of length 1).
- `restrategizeOnFailure` requires a reason string; SP4 passes the most recent
  `failed` log's `reason` in the run, or `''` when the user logged a failure without
  one (`ReviseStrategy` trims and the Strategist prompt tolerates an empty reason).

| Recent non-skip outcomes (newest-first) | Action |
|---|---|
| `failed, failed` (≥2) | `restrategizeOnFailure(active, latest reason)` |
| `completed × 5` (≥5) | `stackOnSuccess(active)` |
| `failed` (1) | none (below threshold) |
| `completed × 4` | none (below threshold) |
| `failed, completed, failed` | none — newest run is one `failed` |
| `skipped, completed × 5` | `stackOnSuccess` — skips dropped |

### 4. Revision notification (reuse SP3)

On any revision the owner gets a `StrategyRevisedNotification` via the `database`
channel — the same plumbing SP3 built, so it appears in `/inbox` and bumps the
shared `unread_notifications_count` badge with no new delivery code:

```php
public function via(object $notifiable): array
{
    return ['database'];
}

/** @return array{type:string, intention_id:int, strategy_id:int, change_reason:string, title:string, approach:string} */
public function toArray(object $notifiable): array
{
    return [
        'type' => 'strategy_revised',
        'intention_id' => $this->strategy->intention_id,
        'strategy_id' => $this->strategy->id,
        'change_reason' => $this->strategy->change_reason,  // stacked_on_success | restrategized_on_failure
        'title' => $this->strategy->intention->title,
        'approach' => $this->strategy->approach,
    ];
}
```

SP3's `InboxController::index` maps a fixed set of `data` fields per row
(`action_id`, `intention_id`, `title`, `fired_at`, `read_at`). SP4 extends that map
with a `type` discriminator (`$notification->data['type'] ?? 'action_due'`) plus the
revision-only fields `change_reason` and `approach` (null for due cues), and widens
the `NotificationData` TS interface to match. `inbox.tsx` then renders a revision row
("Your plan for _X_ changed — now: _approach_") distinctly from a due-cue row, both
linking to `/intentions/{intention_id}`. SP3's `ActionDueNotification` predates the
`type` key, so a missing `type` falls back to `'action_due'` (backward compatible).

### 5. Idempotency, concurrency, failure & cost

- **Double-delivery is self-correcting.** If the job runs twice for one log: the
  second `UpdateRollingSummary` finds no new events and returns `null`; the second
  revision attempt sees the now-superseded strategy and `ReviseStrategy::guardActive`
  throws `StrategyTransitionException`, caught as benign. The per-intention
  `Cache::lock` additionally prevents concurrent LLM spend.
- **Quota.** The `GuardCoachUsage` AI middleware throws `CoachQuotaException` when the
  user is over budget; the job catches it and ends without revising. The streak is
  unchanged, so the next qualifying log retries once budget frees up.
- **Transient LLM errors** (other `CoachException`s, network) propagate → the queued
  job retries up to `$tries = 3` with backoff, then lands in `failed_jobs`. The
  committed `ActionLog` is never affected — logging always succeeds regardless of
  coaching.
- **No active strategy** (all retired) → the job updates the summary and returns
  without revising.

---

## Testing

**Feature — log dispatches the event (`tests/Feature/Actions/LogActionTest.php`, extend):**
- Logging (completed / failed / skipped) dispatches `ActionLogged` once, **after
  commit** (`Event::fake`; assert dispatched with the saved log).
- Existing `LogAction` behavior (status transition, re-arm, mark-cue-read) unchanged.

**Feature — the coaching closure (`tests/Feature/Coach/RunCoachingClosureTest.php`, new):**
Drive the listener directly with `Strategist::fake([...])` / `Summarizer::fake([...])`.
- 2 consecutive `failed` → strategy revised via `restrategizeOnFailure`; new active
  version supersedes; `superseded_reason` is the latest failure reason.
- 5 consecutive `completed` → revised via `stackOnSuccess`.
- 1 `failed`, or 4 `completed` → **no revision**; only the summary updates.
- `skipped` outcomes are ignored: `skipped` between failures does not break a 2-fail
  streak; a `skipped` log alone never revises.
- Mixed run (`failed, completed, failed`) → no revision (newest run length 1).
- `UpdateRollingSummary` is invoked on **every** outcome (a `Summary` row appears when
  there are new events).
- Streak resets after a revision: immediately re-running the closure does not revise
  again (new active strategy has no logs; `StrategyTransitionException` path is benign).
- `CoachQuotaException` from the guard → no revision, no exception escapes the job.

**Feature — revision notifies (`tests/Feature/Coach/RunCoachingClosureTest.php`, same):**
- On revision, the intention's owner receives a `StrategyRevisedNotification` on the
  `database` channel (`Notification::fake`, `assertSentTo`); sent to the owner only.
- Below threshold → no notification.

**Unit/Feature — notification payload (`tests/Feature/Notifications/StrategyRevisedNotificationTest.php`, new):**
- `via()` returns `['database']`.
- `toArray()` shape: `type='strategy_revised'`, `intention_id`, `strategy_id`,
  `change_reason`, `title`, `approach`.

**Feature — the wiring (`tests/Feature/Coach/RunCoachingClosureTest.php`, same):**
- `Event::assertListening(ActionLogged::class, RunCoachingClosure::class)` — proves
  the listener is auto-discovered for the event, so dispatch (tested in
  `LogActionTest`) + handler (tested above) form a connected chain. (Avoids the
  `ShouldDispatchAfterCommit` + `RefreshDatabase` interaction, where after-commit
  callbacks never fire inside a test's wrapping transaction — so the chain is proven
  by dispatch + listening + handler, not a flaky HTTP round-trip.)

**Frontend (vitest) — `resources/js/pages/inbox.test.tsx`, extend:**
- A `strategy_revised` notification renders its revision row (distinct copy, links to
  the loop); a notification with no `type` still renders as a due cue (SP3 regression).

---

## Files touched (anticipated)

**New**
- `app/Events/ActionLogged.php`
- `app/Listeners/RunCoachingClosure.php`
- `app/Notifications/StrategyRevisedNotification.php`
- `tests/Feature/Coach/RunCoachingClosureTest.php`
- `tests/Feature/Notifications/StrategyRevisedNotificationTest.php`

**Modified**
- `app/Actions/LogAction.php` — dispatch `ActionLogged` after commit.
- `config/services.php` — `coach.fail_streak`, `coach.stack_streak`.
- `app/Http/Controllers/InboxController.php` — map `type` + revision fields (`change_reason`, `approach`) in `index`.
- `resources/js/patyourself/types.ts` — widen `NotificationData` (`type`, `change_reason`, `approach`).
- `resources/js/pages/inbox.tsx` — render the `strategy_revised` row type.
- `resources/js/pages/inbox.test.tsx` — row-type tests.
- `tests/Feature/Actions/LogActionTest.php` — `ActionLogged` dispatch tests.

---

## Success criteria

1. Logging an action automatically refolds that loop's rolling summary (a `Summary`
   snapshot appears as events accumulate) — `UpdateRollingSummary` is no longer
   orphaned.
2. Two consecutive failures on the active strategy automatically restrategize it
   (new active version, prior superseded with the user's reason, a fresh action
   authored); five consecutive successes stack toward a harder goal.
3. `skipped` outcomes never count toward or break a streak; below-threshold runs do
   not revise.
4. Every auto-revision produces a per-user `StrategyRevisedNotification` in `/inbox`
   and increments the unread badge; due cues from SP3 still render correctly.
5. The coaching pass runs off the request in a queued, after-commit listener;
   logging stays fast and always succeeds even when the LLM fails or the user is over
   budget.
6. The closure is idempotent: re-delivering the job, or a concurrent run, never
   double-revises or double-spends (lock + `guardActive`); the streak resets to 0 on
   the new active strategy after each revision.
7. All new and affected tests pass (PHPUnit + vitest); `vendor/bin/pint` clean;
   types/lint clean.

## Scope boundary — explicitly NOT in SP4

- **No propose/approve flow** — revision is fully automatic (Decision 1). A
  user-confirmed revision is a possible later slice.
- **No email / web push** — the revision cue is in-app only, on SP3's `database`
  channel; other channels remain deferred.
- **No SP5 progress dashboard** — surfacing strategy history/timeline visually is SP5.
- **No retroactive backfill** — SP4 does not summarize or revise against logs that
  predate it; it acts from the next log forward.
- **No threshold tuning UI** — `fail_streak` / `stack_streak` are config only.
- **No changes to `ReviseStrategy` / `UpdateRollingSummary` internals** — SP4 only
  calls them; their LLM behavior, versioning, and prompts are unchanged.
- **No new model, migration, or table** — reuses SP1/SP3 schema.
