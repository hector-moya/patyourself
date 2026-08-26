# Check-in model — occurrences + the MCP write surface

Date: 2026-08-26
Status: approved (design)
Supersedes nothing. Consumes: `2026-08-25-lab-notebook-reframe-design.md` (phases 1 & 2, merged)
and `2026-08-26-lab-notebook-phases-3-4-carryforward.md`.

## Context

The app is the persistence and statistics layer; Claude is the coach, over MCP. So the MCP
surface *is* the coaching surface — anything the coach cannot read or write does not exist.

Today the server can read a little and write almost nothing:

| Tool | State |
| --- | --- |
| `list-loops`, `get-loop`, `today-actions`, `loop-progress` | read, thin |
| `log-action-outcome` | the only write |
| `create-loop` | write, creates paused |

`StartExperiment` and `ConcludeExperiment` shipped in phase 2 **with no callers** — starting a
strategy version currently requires tinker. And nothing reads a failure reason back: the single
most useful field in the system is write-only.

This spec covers the brief's build steps 1–4. Screens (5, 7), action CRUD / `update-loop` (6)
and `log-note` (8) are out of scope and stay for their own spec.

## The behaviour being built for

The user opens a chat every few days, irregularly. One conversation must:

1. **Catch up the record** — Claude sees which occurrences went unlogged, asks what happened,
   logs them retroactively in the user's own words.
2. **Read the pattern** — Claude pulls outcomes with reasons and timestamps and finds where the
   chain actually breaks.
3. **Iterate the strategy** — Claude starts a new experiment at a different intervention point,
   with a rationale, superseding the old one with a reason.

## The root problem: an action is not an occurrence

`actions` is one mutable row that rolls forward. `LogAction::closeOrRearm()` fast-forwards
`scheduled_for` past every missed slot when an outcome is logged, and `action_logs.action_id` is
the only key an outcome carries. Three consequences:

- a slot that was never logged leaves **no trace**, so nothing is catch-up-able;
- an outcome is dated by `logged_at` (when it was *typed*), not by the occasion it describes;
- two meals a day on one action are indistinguishable.

### Decision: an occurrence is a first-class row, the action stays the standing prescription

Introduce `occurrences`. An occurrence is one instance of a recurring action: an action, a
scheduled datetime, and at most one outcome. Outcomes attach to occurrences.

The `Action` row keeps its current job and its current behaviour:

| Row | Responsibility |
| --- | --- |
| `Action` | the standing prescription + `scheduled_for` as the **next due** pointer (what `TriggerEngine`, `TodaysActions`, the digest and the action cards read) |
| `Occurrence` | **each instance** — the durable, dated record and the unit an outcome attaches to |

These are two responsibilities, not two copies of one. Roll-forward is deliberately **kept**, so
the web surfaces, the trigger engine, the notification flow and their tests are untouched by this
phase. Phase 3 (Notebook UI) is where roll-forward should be retired and the "due" definition
repointed at occurrences; that is called out here so it is a decision and not a drift.

**Consequence — the series anchor.** Because `scheduled_for` moves, it cannot also mark where the
series began. Add `actions.series_started_at`: set to the action's initial `scheduled_for` at
creation, never mutated. Materialisation walks forward from it.

**Consequence — roll-forward only on the live slot.** Logging a *catch-up* occurrence from three
days ago must not move the next-due pointer. `LogAction` rolls forward only when the occurrence
being logged is at or after the action's current `scheduled_for`.

### Materialisation

Lazy, on read, idempotent, never triggered by a write path.

- Walk from `series_started_at` forward with `Schedule::advance()` (which preserves wall-clock
  time in the user's timezone, so it is DST-correct) up to `now`.
- Insert with `upsert` against a unique index on `(action_id, scheduled_for)` — safe under
  concurrent reads.
- Only actions on **active** loops. Paused and archived loops do not materialise. Archived actions
  do not materialise.
- A one-off action (`recurrence` null, `scheduled_for` set) materialises exactly one occurrence.
- An anchored action (`scheduled_for` null) materialises none — it has no scheduled time, so it can
  never go unlogged. It is logged ad hoc against a user-supplied `occurred_at`.
- Bounded: at most 1000 slots per action per pass, so a very old anchor cannot run away.

### Ad hoc logging

An anchored or one-off log with no occurrence supplies `action_id` + `occurred_at`. An occurrence
is created at exactly that datetime and the outcome attaches to it. So **every** outcome has an
occurrence and the read models never branch. There is no `kind` column: an ad hoc occurrence is
created together with its outcome, so it is never in the unlogged set.

### One outcome per occurrence

Enforced by a unique index on `action_logs.occurrence_id`. Re-logging returns an error, not a
second row.

`action_logs.action_id` **stays**, non-null. It is the denormalised parent pointer that
`LoopProgress::experimentsFor()` and `Intention::actionLogs()` already join on, and keeping it
makes this migration additive. `occurrence.action_id` and `log.action_id` must agree; `LogAction`
is the only writer and sets both.

### Existing data

One loop, one action, one failure logged 24 August. Per the brief the migration is pragmatic:
each existing log gets a synthesised occurrence at its `logged_at`, and `series_started_at` is
backfilled from the current `scheduled_for`. Because that anchor is already rolled forward into the
future, materialisation cannot collide with a synthesised row. The cost is that the two days
between the existing log and the anchor never materialise — accepted, and cheaper than snapping
historical logs onto reconstructed slots.

## Outcome semantics

| Outcome | Meaning | Counts in completion rate? |
| --- | --- | --- |
| `completed` | did the thing | yes, numerator + denominator |
| `failed` | the occasion happened, the strategy did not hold — **including "didn't think about it"** | yes, denominator |
| `skipped` | **the occasion never happened** — no meal, travelling, ill | **no** — excluded from the denominator, surfaced as its own count |

`reason` is mandatory on `failed`, enforced at the tool boundary, and stored **verbatim** — never
normalised, summarised or sentence-cased. It is the input to the next strategy version.

## Context capture

Two new columns on `action_logs`:

- `context` — free text, the mechanics of what happened. The primary record.
- `context_fields` — json, deliberately tiny: `place` (string), `with_others` (bool),
  `preceded_by` (string). Unknown keys are rejected at the tool boundary so the shape cannot drift.

## Tools

`log-action-outcome` is **replaced** by `log-outcome`. `McpEndpointTest` asserts the exact ordered
tool-name list and that every advertised name appears in the server `#[Instructions]`; both move
together, and the instructions get the rewrite phase 4 owed them.

### `log-outcome`

```
log-outcome(
  occurrence_id?, action_id?, occurred_at?,
  outcome, reason?, context?, context_fields?
)
```

Exactly one of `occurrence_id` or (`action_id` + `occurred_at`) — validated at the boundary.
`reason` required when `outcome` is `failed`. Rejects an occurrence that already has an outcome,
and any occurrence not owned by the caller.

### `pending-outcomes(since?)`

Materialises, then returns unlogged occurrences with a past `scheduled_for`, across the user's
**active** loops, newest first, with loop title, action title and scheduled datetime.

`since` defaults to 14 days back — a check-in opens on the recent window, not an audit. Older
occurrences are never expired, never auto-marked and remain available by passing an older `since`.
Results are capped at 100 and the response says so via `truncated`. No overdue count, no badge —
the response carries no framing that treats a backlog as debt.

### `loop-outcomes(intention_id, since?)`

The reasons, finally readable. Per entry: occurrence datetime, action title, outcome, **reason
verbatim**, context, context fields, and the strategy version that was active when it happened
(via `actions.strategy_id`). Newest first, `since` optional, capped at 200.

### `start-experiment`

```
start-experiment(
  intention_id, intervention_point, approach,
  rationale?, supersedes_reason?, review_after_days?, change_reason?
)
```

Wraps `StartExperiment`. Strategy versions are append-only: it supersedes the active version and
creates the next. No draft, no approval step — the safety is immutability.

**This tool is where the deleted `ReviseStrategy::revise()` validation now lives.**
`AuthoredStrategy` has no guard of its own, so the tool must reject an `intervention_point` outside
`Strategy::INTERVENTION_POINTS` and a blank `approach`, or malformed versions reach the database.

`review_after_days` is optional; null means open-ended, which must never render as a countdown.
`change_reason` defaults to `restrategized_on_failure`.

### `loop-progress` — two scopes

Returns both blocks, per the brief:

- `current_version` — streak, completion rate, totals, skip count **since the active version began**.
  This is what tells a failing strategy from a working one.
- `lifetime` — the same across all versions.

Skipped is excluded from both denominators and reported separately in both.

### `get-loop` — outcomes per version

Each version in the timeline gains `outcomes_recorded`, so the coach can tell a strategy that
**failed** from one that was **never tested**.

## Constraints (carried from the brief, binding on every task)

- **Reasons are verbatim.** No tidying anywhere in the path.
- **Strategy versions are append-only.** Supersede, never overwrite.
- **No quantities on eating loops.** No calories, portions, weights or numeric targets anywhere in
  the data model. A loop that becomes a number stops being a loop.
- **Failure language is about the strategy, never the user.** No field, enum, or copy framing an
  outcome in terms of discipline, willpower or motivation.
- **No gamification.** A streak is a statistic. No badges, levels or celebratory states.
- **The notebook never nags.** No overdue counts, no red states on a backlog.

## Open decisions inherited, not resolved here

From the carry-forward notes, unchanged and not blocking this phase:

1. `ConcludeExperiment` clears `review_at`, destroying the only record of planned run length.
   Still nothing calls it after this phase — `conclude-experiment` is not in scope here.
2. `laravel/ai` remains a dependency with no consumer; removing it needs approval.
3. `ANTHROPIC_API_KEY` should be deleted from Forge and `.env` at deploy time.

## Out of scope

Screens (outcome history, strategy timeline, in-app catch-up), `add-action` / `update-action` /
`remove-action`, `update-loop`, `log-note`, `conclude-experiment`, MCP prompts.
