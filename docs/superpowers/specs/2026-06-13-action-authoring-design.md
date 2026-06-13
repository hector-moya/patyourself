# SP1 — Action Authoring (design)

**Date:** 2026-06-13
**Status:** Approved, ready for implementation planning
**Program slice:** SP1 of the "close the habit loop" decomposition (see _Program context_ below)

---

## App intent (one paragraph)

PatYourSelf is a conversational habit-change coach. It models every habit as an
_Atomic Habits_ loop — **cue → craving → response → reward** — that is editable,
and treats each attempt as a CBT-style behavioural experiment: when the user
fails, take their stated reason at face value and adjust the plan, never
moralise. The user describes a habit in chat; the Coach agent authors a
structured **Intention** (the loop) plus an initial **Strategy** (where in the
chain to intervene). The user logs outcomes; the coach restrategises on failure
(new Strategy version) or stacks on success. The whole point of a habit app is to
**prompt the right behaviour at the right moment** — to deliver the cue.

## The problem this slice solves

Loops get created, but nothing ever produces a concrete, time-bound thing to
_do_, and nothing prompts the user at the right moment. Concretely, in the
current codebase:

- **`Action` rows are never created.** No `Action::create` / `actions()->create`
  anywhere in `app/`. The coach authors `Intention` + `Strategy` and stops. So
  `intention->activeAction` is effectively always empty (only seeders make
  Actions), and `POST /actions/{action}/logs` has nothing real to log against.
- **`actions.scheduled_for` and `actions.recurrence` are dead columns** — present
  in the model's `$fillable`/casts, never written, never read.
- **The "ActionCard" UI renders the _Intention_, not the Action.** It shows the
  loop anatomy + the strategy's `approach` as a "tactic" (`action-card.tsx:24,46`)
  and never surfaces a time. `activeAction` is defined-but-unrendered.

SP1 fixes the **first missing link**: turn a Strategy into a concrete **Action**
that carries a schedule (time + recurrence), let the user adjust that schedule,
and surface it in the UI. It does **not** fire anything — that is SP2.

---

## Program context (decomposition)

The remaining work, ordered by dependency. SP1 is the keystone.

```
        CREATE → CUE → ACT → LOG → LEARN → (new strategy) → ...

SP1  Action authoring        Strategy → Action card (title, scheduled_for,    ★ THIS SPEC
     (the missing link)      recurrence). AI proposes the schedule; user can
                             edit it. Unblocks everything below.
        ┌──────────────────────────┼───────────────────────────┐
        ▼                          ▼                            ▼
SP2 Trigger engine        SP3 Cue delivery            SP4 Auto-coaching closure
    scheduler scans due       in-app inbox + web          LogAction → (queued)
    actions, marks active,    push + email reminder.      ReviseStrategy +
    spawns next recurrence.   needs SP2.                  UpdateRollingSummary.
    needs SP1.                                            needs SP1.
        └──────────────┬───────────┘                            │
                       ▼                                        ▼
              SP5 Progress dashboard  ◄──────────────  (data from logs/summaries)
SP6  Onboarding / first-run (cross-cutting, YAGNI candidate)
```

---

## Decisions (locked)

| # | Decision | Choice |
|---|----------|--------|
| 1 | Who sets the schedule | **AI proposes, user can tweak** |
| 2 | Recurrence richness | **`once` / `daily` / `weekdays` / `weekly`** |
| 3 | Cue types | **Clock-time fires; anchored cues stored but un-triggered** |
| 4 | Editing after creation | **Yes — editable on the card/detail** |
| 5 | How the Action is authored | **Approach A: extend the existing agents' schema** (no extra LLM call) |
| 6 | Timezone | **Add `users.timezone`**, captured from the browser |
| 7 | Action/Strategy relationship | **Exactly one active Action per active Strategy** |

### Approach A vs B vs C (for decision 5)

- **A (chosen):** Add an `action` sub-object to `IntentionAuthor` and `Strategist`
  structured output. The same Haiku call that picks the intervention point also
  phrases the concrete action and proposes time + recurrence. One coherent
  decision, no extra metered call.
- **B (rejected):** A separate `AuthorAction` agent. Cleaner single-responsibility
  and reusable, but a second metered LLM call per loop/revision — YAGNI now.
- **C (rejected):** Derive the action title/description deterministically from
  `strategy.approach`, LLM only for the time. Cheapest, but robotic copy that
  ignores the loop's nuance.

---

## Design

### 1. Schedule model (reuses the two existing columns)

- `actions.scheduled_for` (datetime, nullable) = the **next concrete fire time**,
  stored UTC, computed from the user's local time + tz.
- `actions.recurrence` (string, nullable) = `daily | weekdays | weekly | null`.

Three states fall out cleanly:

| State | `scheduled_for` | `recurrence` | Meaning |
|-------|-----------------|--------------|---------|
| Scheduled recurring | set | `daily`/`weekdays`/`weekly` | e.g. every weekday 7:00 |
| One-off | set | `null` | fire once, then done |
| Anchored / un-triggered | `null` | `null` | cue text carries "after morning coffee"; shown, never fired |

**New:** `app/Services/Scheduling/Recurrence.php` (string-backed enum:
`Daily`, `Weekdays`, `Weekly`) and `app/Services/Scheduling/Schedule.php` (value
object) own the next-occurrence math:

- `daily` → +1 day
- `weekdays` → next day that is Mon–Fri (skips Sat/Sun)
- `weekly` → +7 days
- `once` (`recurrence === null`, `scheduled_for` set) → no next occurrence

`Schedule` is pure (takes a "now", a local time-of-day, a recurrence, a tz; returns
the next UTC `scheduled_for`). **SP2 reuses it verbatim** to compute the following
occurrence after firing. `app/Services/Scheduling/` is a new sub-folder under the
existing `app/Services/` namespace (no new base folder).

### 2. Timezone

- Migration: add `users.timezone` (string, nullable, default `config('app.timezone')`).
- Capture: on first authenticated page load where `timezone` is unset, the
  frontend PATCHes the browser IANA tz (`Intl.DateTimeFormat().resolvedOptions().timeZone`)
  to a dedicated lightweight `PATCH /settings/timezone` (its own controller +
  Form Request; not folded into the Fortify profile action). Stored once; a
  user-facing timezone picker is out of SP1 scope — default + browser capture is
  enough.
- All `scheduled_for` math converts the AI/user-provided local `HH:MM` in this tz
  to UTC for storage, and back to local for display.

### 3. AI authoring (Approach A)

Extend `IntentionAuthor` **and** `Strategist` structured output with an `action`
block:

```jsonc
"action": {
  "title":       string,                       // imperative: "Set your shoes by the door"
  "description": string | null,
  "schedule": {
    "kind":       "clock | anchored",
    "time":       "HH:MM" | null,               // user-local, required when kind=clock
    "recurrence": "once | daily | weekdays | weekly" | null,
    "anchor":     string | null                 // required when kind=anchored, e.g. "after morning coffee"
  }
}
```

Prompt rules added to both agents:

1. If the user states or clearly implies a clock time, set `kind=clock` with that
   `time`.
2. If the habit is genuinely anchored to another routine (habit-stacking), set
   `kind=anchored` with a short `anchor` phrase and leave `time` null.
3. Otherwise propose a sensible default `time` + `daily` (the user can tweak).
4. `title` is an imperative restatement of the strategy's `approach` — the single
   concrete thing to do.

New value object `app/Services/Coach/Authoring/AuthoredAction.php` mirrors
`AuthoredStrategy`: a `fromStructured(array): self` that validates `kind`,
`recurrence` enum, and `HH:MM` format, and throws `CoachException::emptyResponse`
on malformed input (consistent with the existing authoring guards). `AuthoredAction`
is carried alongside `AuthoredStrategy` (on `AuthoredIntention` for the create
path; returned next to the revision for the restrategise path).

### 4. Persistence

- **`AuthorIntention::persist()`** — after creating Strategy v1, create the Action
  bound to it. `Schedule` converts the authored local time → UTC `scheduled_for`;
  `recurrence` stored as the enum string (or null). Anchored → both null.
  `metadata` keeps `{ authored_by, prompt_version, schedule_kind, anchor }`.
- **`ReviseStrategy::supersedeAndCreate()`** — when a new Strategy version becomes
  active, **archive the prior active Action(s)** (`status = archived`) and create a
  fresh Action bound to the new Strategy.
- **Invariant:** exactly one active Action per active Strategy. Enforced in these
  two write paths (the only places Actions are authored) and covered by tests.
- **Side benefit:** `POST /actions/{action}/logs` now logs against a real,
  freshly-authored Action instead of a seeder-only row.

### 5. Editing the schedule (decision 4)

- Route: `PATCH /actions/{action}` (web) + API parity under the existing
  `routes/api.php` action group. Wayfinder-typed; consumed from `@/actions`.
- New `app/Actions/RescheduleAction.php`: accepts `{ time: "HH:MM"|null,
  recurrence: "once|daily|weekdays|weekly"|null, kind: "clock|anchored",
  anchor: string|null }`, recomputes `scheduled_for` via `Schedule` in the user's
  tz, persists. Validated by a Form Request.
- Authorization: add `update` to `ActionPolicy` (ownership via
  `action->intention->user_id`), gated like the existing `IntentionPolicy`.

### 6. Frontend

- `IntentionData.activeAction` TS type + the `IntentionController` / API resource
  serialization include the Action's `{ id, title, description, scheduled_for,
  recurrence, schedule_kind, anchor, status }`.
- `ActionCard` gains a **schedule chip** rendered from `activeAction`:
  - clock recurring → `"Daily · 7:00 AM"`, `"Weekdays · 7:00 AM"`, `"Weekly · 7:00 AM"`
  - one-off → `"Tomorrow · 7:00 AM"` / `"Jun 14 · 7:00 AM"`
  - anchored → muted `"After morning coffee"`
- A small inline **schedule editor** (a time `<input type="time">` + a recurrence
  `<select>`, plus an "anchored / no fixed time" toggle) submits the PATCH via an
  Inertia form. Optimistic update with rollback on failure (Inertia v3).
- Times render in the user's tz.

### 7. Testing

Feature tests:
- `AuthorIntention` creates exactly one active Action bound to Strategy v1, with a
  correctly UTC-converted `scheduled_for`.
- `ReviseStrategy` archives the prior active Action and creates a new active Action
  bound to the new Strategy version (one-active invariant holds).
- Anchored authoring → Action with null `scheduled_for` + null `recurrence`.
- `PATCH /actions/{action}` recomputes `scheduled_for` in the user's tz; rejects a
  foreign user's action (policy).
- Logging still works against the newly-authored Action.

Unit tests:
- `Schedule` next-occurrence math: `daily`, `weekdays` (Fri→Mon, Sat/Sun skip),
  `weekly`, `once` (no next). tz/DST conversion of `HH:MM` → UTC.
- `AuthoredAction::fromStructured` validation: bad `kind`, bad `recurrence`,
  malformed `time`, missing `anchor` when anchored.
- Agent schema tests: the new `action` block validates and round-trips.

### 8. Scope boundary — explicitly NOT in SP1

These belong to later slices and must not creep in:

- **No scheduler / cron** scanning due actions (SP2).
- **No notifications** — email / push / in-app inbox (SP3).
- **No recurrence _firing_ or instance spawning** at runtime. SP1 only stores the
  rule and computes the _first_ `scheduled_for`; SP2 reads it, fires, and computes
  the next one (reusing `Schedule`).
- **No auto-revision / auto-summary** wiring (SP4).
- **No settings UI for timezone** beyond default + one-time browser capture.

---

## Files touched (anticipated)

**New**
- `app/Services/Scheduling/Recurrence.php` (enum)
- `app/Services/Scheduling/Schedule.php` (next-occurrence VO)
- `app/Services/Coach/Authoring/AuthoredAction.php` (VO)
- `app/Actions/RescheduleAction.php`
- `app/Http/Requests/RescheduleActionRequest.php` (Form Request)
- `database/migrations/*_add_timezone_to_users_table.php`
- Tests for each of the above + the authoring paths.

**Modified**
- `app/Ai/Agents/IntentionAuthor.php` (schema + prompt: `action` block)
- `app/Ai/Agents/Strategist.php` (schema + prompt: `action` block)
- `app/Services/Coach/Authoring/AuthoredIntention.php` (carry `AuthoredAction`)
- `app/Actions/AuthorIntention.php` (persist Action)
- `app/Actions/ReviseStrategy.php` (archive old + create new Action)
- `app/Policies/ActionPolicy.php` (`update`)
- `routes/web.php`, `routes/api.php` (`PATCH /actions/{action}`)
- `app/Http/Controllers/.../IntentionController.php` + API resource (serialize `activeAction` schedule)
- `resources/js/patyourself/chat/action-card.tsx` (schedule chip + editor)
- `resources/js/patyourself/types.ts` (`activeAction` shape)
- `app/Models/User.php` (timezone), settings/profile path for tz capture
- `database/factories/ActionFactory.php` (align with new metadata shape if needed)

---

## Success criteria

1. Creating a loop in chat now also persists a concrete, scheduled (or anchored)
   Action bound to Strategy v1, visible on the card with its time/recurrence.
2. Revising a strategy archives the old Action and authors a new one; never more
   than one active Action per active Strategy.
3. The user can change an Action's time + recurrence and it persists correctly in
   their timezone.
4. All new and affected tests pass; `vendor/bin/pint` clean.
5. Nothing fires yet — the schedule is data, ready for SP2 to consume.
