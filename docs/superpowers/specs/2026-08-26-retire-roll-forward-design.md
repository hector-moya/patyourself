# Retire roll-forward — occurrences become the single source of truth for what is due

Date: 2026-08-26
Status: approved (design)
Consumes: `2026-08-26-check-in-occurrences-design.md`, `2026-08-26-notebook-tail-design.md` (both built).

## Context

The check-in branch added `Occurrence` — one row per instance of an action, materialised lazily
from `actions.series_started_at` — and hung outcomes off it. It was added *alongside* the existing
mechanic rather than in place of it, deliberately, so that nothing broke while the check-in model
was proved out.

That leaves two answers to the same question. `actions.scheduled_for` is still the "next due"
pointer that `TriggerEngine`, `TodaysActions`, the daily digest and the action cards read, and
`LogAction::closeOrRearm` still rolls it forward when the live slot is logged. Occurrences are the
full grid; `scheduled_for` is a cursor over it.

The cursor is not merely redundant, it is lossy. `Schedule::nextAfter()` fast-forwards *past* every
missed slot, so a missed occasion silently disappears from the digest and the today list. That was
the right behaviour when the cursor was all there was — an accumulating backlog is precisely what
this notebook must never show — but it means the cursor and the occurrence grid disagree about
history by construction.

This spec retires the cursor.

## What already exists

Worth stating so the plan does not rebuild it:

- `Occurrence` with `action_id` + `scheduled_for`, a unique index on the pair, an `unlogged` scope
  and a `log` has-one.
- `MaterialiseOccurrences` — lazy, idempotent, upsert-based, DST-correct, capped at
  `MAX_SLOTS_PER_ACTION = 1000`. Walks from `series_started_at` with `Schedule::advance()`.
- `actions.series_started_at` — the anchor. `RescheduleAction` re-anchors it; `CreateAction`,
  `PersistAuthoredIntention` and `StartExperiment` set it.
- `/catch-up`, `pending-outcomes`, `log-outcome` and `loop-outcomes` already read occurrences.
- `LogAction` already attaches every outcome to an occurrence.

The work is therefore *removal plus repointing*, not new machinery.

## Decisions

### Due means today's local day, and only today's local day

`TodaysOccasions` selects unlogged occurrences whose `scheduled_for` falls inside the user's local
day. Anything older is invisible to the digest, the action cards, the trigger engine and
`today-actions`. It is reachable only through `/catch-up` and `pending-outcomes`, which are
opt-in surfaces the user goes looking for.

This is the constraint doing the work, not a convenience. The literal reading of "occurrences are
the source of truth" is *every unlogged past occurrence is due*, and that builds a seven-item due
list out of a week away — an overdue count, a red backlog state, and a digest that nags. The
window is what keeps retirement of the cursor from changing the notebook's character. In practice
it also preserves current behaviour closely, because fast-forwarding past misses already produced
roughly this set.

Missed occasions are not deleted, hidden or expired. They wait, uncounted and unlabelled, on
`/catch-up`. Nothing renders a number over them.

### Fire-state moves onto the occurrence; `actions.status` becomes a lifecycle

`occurrences.fired_at` replaces the guarded `pending → active` flip on the action row.
`TriggerEngine` guards on that column instead, so its two idempotency layers survive unchanged: the
scheduler's `withoutOverlapping()` and the engine's own conditional update.

`actions.status` collapses to `active | archived`. `pending`, `active`, `completed` and `skipped`
retire. A standing prescription was never the thing that completed — a daily action is never
"completed", which is why `closeOrRearm` had to immediately reset it to `pending`. That reset *is*
roll-forward, and it goes with it.

The alternative — keeping all five values as a denormalised echo of occurrence state — was
rejected. It reintroduces two writers for one meaning, which is the exact problem this branch
exists to remove.

### A cue-anchored action stays a standing card

A cue-anchored action has no clock time, so `series_started_at` is null and it materialises no
occurrences at all. Under an occurrences-only today list it would vanish.

It therefore unions in from the action row: occurrences own *scheduled* due-ness, and an anchored
action has no schedule. Logging one stamps its occasion at that moment, which is exactly what
`LogAction::freeSlotAt()` already does.

The alternative — giving anchored actions an implied daily cadence at local midnight — was
rejected on the notebook's own terms. An anchored habit done twice a week would leave five
permanently unlogged occasions a week sitting on `/catch-up`, forever. That manufactures misses
out of a habit that has no schedule to miss.

There are currently no anchored actions in the owner's data, so this is a code-path decision with
no migration consequence.

### The materialisation horizon moves to the end of the local day

`MaterialiseOccurrences` currently stops at `now`. That is fine for a catch-up list, which only
ever looks backwards, but it means *nothing later today has a row* — and `today-actions` splits its
output into `due_now` and `upcoming`. Under an occurrences-only read, `upcoming` would always be
empty.

The horizon becomes the end of the user's local day. `due_now` is then `scheduled_for <= now` and
`upcoming` is the rest of the day, both selected from real rows.

Two consequences follow and are handled rather than accepted:

- **The pass must become incremental.** `actions:fire` runs every minute. Rewalking from the anchor
  and re-upserting up to 1000 rows every 60 seconds is waste. The pass builds its slot list, reads
  the action's existing `scheduled_for` values, and upserts only the difference — usually nothing.
  The walk itself stays a full walk from the anchor, because resuming from the last materialised
  slot is wrong after a re-anchor: the old grid and the new one do not share a phase.
- **`RescheduleAction` must purge.** Its current comment notes that already-materialised occasions
  are left untouched, which was harmless when the horizon was `now`. With future rows it strands
  phantom slots on the abandoned cadence. It now deletes that action's unlogged, future occurrences
  before re-anchoring. Logged occasions are never touched — the record is not rewritten.

### Materialisation runs from the trigger engine

`actions:fire` materialises for users with active loops, then fires. Read paths keep calling
`MaterialiseOccurrences` as they do now.

This does not break the "lazy, never as a side effect of a write" invariant. That invariant's real
content is that *logging an outcome* must never conjure rows, so that the check-in cannot invent
occasions it then asks about. A scheduled read-job that prepares today's grid before reading it is
the same laziness, on a cron's read rather than a user's.

### The cursor and the retired statuses are dropped in this branch

One branch, one deploy, one consistent model. No decoy column left in the schema for a follow-up
that tends not to happen.

Nothing is lost. `scheduled_for` is fully derivable from `series_started_at` + `recurrence`;
`completed` and `skipped` are derivable from an action's logs; `pending` and `active` are derivable
from occurrence state. `down()` re-adds both columns and recomputes them.

## Architecture

### Schema

| Change | Table | Note |
| --- | --- | --- |
| Add `fired_at` (nullable timestamp, indexed) | `occurrences` | The fire guard. Null means not yet fired. |
| Drop `scheduled_for` and its index | `actions` | The cursor. |
| Normalise `status` | `actions` | `archived` stays; every other value becomes `active`. |

`down()` re-adds `actions.scheduled_for` and its index, recomputes each row's next slot from
`series_started_at` + `recurrence`, sets every non-archived action back to `pending`, and drops
`occurrences.fired_at`. It is a faithful restore of the model, not of each row's exact prior
value: an action that was `active` or `completed` before the migration comes back as `pending`,
and the trigger engine re-derives the rest on its next pass.

Normalising `completed` and `skipped` to `active` is safe because a closed action is protected by
its own log, not by its status. A completed one-off materialises exactly its anchor slot, that
slot carries the completion, and a logged occurrence is never due. **One artefact to verify rather
than assume:** migration `093412` backfilled pre-branch logs onto occurrences stamped at
`logged_at`, not at the action's scheduled time, so an action logged at 08:47 against an 08:00
anchor has its log on an 08:47 occurrence while materialisation also produces an unlogged 08:00
one. That duplicate already exists in the current model — this branch does not create it — and the
local-day window keeps it off the today list, but the migration rehearsal must confirm it produces
no new due item and it should be counted in the rehearsal output rather than passed over.

The `action_logs` rollback trap from the previous branch applies to any index this migration adds:
on MySQL 8 an index needed by a foreign key cannot be dropped before the constraint. `fired_at`
carries no foreign key, so this migration is not exposed to it — but the rollback still gets
exercised on MySQL, not only SQLite.

### Firing

The local-day window is per user, so the engine iterates users with active loops — the same shape
as `MaterialiseOccurrences::forUser()` — rather than running one global query:

```
TriggerEngine::fireDueOccurrences(): int

  for each user with an active loop:
    window = [startOfDay, endOfDay] in that user's timezone, as UTC

    Occurrence::query()
      ->unlogged()
      ->whereNull('fired_at')
      ->where('scheduled_for', '<=', now())
      ->whereBetween('scheduled_for', window)
      ->whereHas('action', not archived)
      ->whereHas('action.intention', user + active)

    per row: guarded update where fired_at is null -> affected === 1 wins
             -> OccurrenceFired::dispatch($occurrence)
```

The window is load-bearing for firing, not only for the today list: without it, an app outage of
three days would deliver three days of stale cues the moment it came back. With it, a slot missed
during a one-hour outage still fires when the engine recovers, because it is still inside today.

`ActionFired` is renamed `OccurrenceFired` and carries the occurrence; the action is reachable
through it. `SendDueNotification` adds `occurrence_id` to the notification payload.
`LogAction::markCueAnswered` matches on `occurrence_id`, falling back to `action_id` so cues
delivered before the deploy still clear when answered.

### What is due today

`TodaysOccasions::for(User): Collection` replaces `TodaysActions`, returning a uniform entry per
item:

```
{
  action: Action,
  occurrence: ?Occurrence,          // null for anchored
  scheduled_for: ?CarbonImmutable,  // null for anchored
  due: 'due_now' | 'upcoming' | 'anchored',
}
```

Scheduled entries come from occurrences in the local-day window. Anchored entries union in from
the action row: non-archived, `series_started_at` null, on an active loop.

`next_occurrence_at`, which three resources expose in place of the retired cursor, is defined once
and identically in all three: **the earliest unlogged occurrence at or after now**, or null when
there is none. It is a read of the grid, not a stored value, and it stops at the materialisation
horizon — so for an action with nothing left today it reads null rather than reaching into
tomorrow. That is the honest answer: tomorrow's slot does not exist yet.

Consumers: `DailyDigestNotification`, `TodayActionsTool`, the action cards. `today-actions` gains
`anchored` as a third value of `due`; its `#[Description]` says what the three mean and that the
list is today's, not a backlog.

### `LogAction`

Deleted outright: `closeOrRearm()`, `isLiveSlot()`, `actionStatusFor()`. Between them they are
roll-forward.

`liveSlotFor()` survives, because callers that log "the action" without naming an occasion still
exist — the web action card and `Api\ActionLogController`. It becomes: today's unlogged occurrence
at or before now, latest first; otherwise `freeSlotAt(now())`, which is also the anchored path.
`freeSlotAt()`, cue-answering and the `ActionLogged` dispatch are unchanged.

The action row is no longer written by the logging flow at all.

### Downstream reads

| Site | Change |
| --- | --- |
| `Intention::activeAction()` | Latest non-archived action, instead of `whereIn(status, [pending, active])`. |
| `IntentionResource` | `scheduled_for` → `next_occurrence_at`. |
| `DescribesActionShape` | `scheduled_for` → `next_occurrence_at`. |
| `Api\ActionController` | `scheduled_for` → `next_occurrence_at`. |
| `StartExperiment` | Carries over non-archived actions instead of pending/active. |
| `UpdateIntention` | Shifts `series_started_at` on a timezone/time change, and purges unlogged future occurrences. |
| `CreateAction`, `PersistAuthoredIntention`, `RescheduleAction` | Stop writing `scheduled_for`; keep writing `series_started_at`. |
| `MaterialiseOccurrences` | Excludes archived actions — unchanged, the status value survives. |
| `resources/js/patyourself/types.ts` + action-card / catch-up components | Follow the resource shapes. |

## Error handling

- `freeSlotAt()` keeps its `RuntimeException` after 60 collisions in one second.
- An unrecognised recurrence token makes `Schedule::advance()` return null, which materialises
  exactly the anchor — one-off behaviour. Already the case; no new path.
- `MAX_SLOTS_PER_ACTION` stays 1000. With the horizon at end-of-day it is a guard against a runaway
  anchor, not a routine limit.
- A user with no timezone falls back to `config('app.timezone')`, as everywhere else.

## Testing

- **Local-day window boundaries in a non-UTC timezone**, explicitly. The two carry-forwards record
  two real bugs SQLite hid on this project, one of them a timezone-dependent date render. Every
  window assertion runs under a non-UTC user.
- Materialisation: the end-of-day horizon, the incremental diff writing nothing on a second pass,
  and DST correctness across a transition.
- `RescheduleAction` purges unlogged future occurrences and leaves logged ones alone.
- Firing: idempotency on the new `fired_at` guard, including a concurrent second run; occurrences
  outside the window never fire; archived and paused loops never fire.
- `TodaysOccasions`: the anchored union, the `due_now` / `upcoming` split, and a missed yesterday
  occurrence appearing in neither.
- `LogAction`: no write reaches the action row; catching up an old occasion still changes nothing
  about today.
- Migration up → down → up on **MySQL 8 and SQLite**, with production-shaped data — an action mid-
  series with logs behind it, plus a completed one-off whose log sits on a `logged_at`-stamped
  occurrence, to confirm the `093412` artefact surfaces no new due item.
- Full PHP suite on both drivers, full Vitest suite, `npm run build`, `npm run lint`.

## Constraints this branch is bound by

- **The notebook never nags.** No overdue counts, no red backlog states, no number rendered over
  the unlogged set.
- **`skipped` = the occasion never happened.** Unchanged; still out of completion-rate
  denominators.
- **Reasons and notes are verbatim.** No path here touches them, and none may start.
- **Strategy versions are append-only.** Unchanged.
- **Failure language is about the strategy, never the user.** Applies to any new copy in the digest
  or the cards.

## Out of scope

`conclude-experiment` and the `review_at` retention change; `write-reflection` and the `summaries`
writer; MCP prompts; the dashboard notebook reframe; the `/log` fast-capture screen; the `/loops`
index redesign; the item-7 cleanups (dead chat-era CSS, duplicate `StartExperimentTest` cases,
`CreateLoopToolTest::test_prompts_no_agent`, the callerless `ActionLogged` event, the
`/api/intentions` naming); removing `laravel/ai`.

## Known limitation, stated rather than fixed

For actions that had already rolled forward before migration `093411`, `series_started_at` was
backfilled from the *rolled-forward* `scheduled_for`. It therefore marks where the cadence resumed,
not where the series truly began, and occurrences earlier than that exist only where a log existed
to synthesise one from.

Dropping `scheduled_for` does not worsen this — the information was already gone the moment the
cursor moved. But it does make it permanent, and it is worth knowing when reading a long-running
loop's early history: the grid starts at the anchor, and the anchor is not always the beginning.
