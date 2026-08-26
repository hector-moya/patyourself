# The notebook tail — screens, action CRUD, update-loop, notes

Date: 2026-08-26
Status: approved (design)
Consumes: `2026-08-26-check-in-occurrences-design.md` (steps 1–4, built).

## Context

Steps 1–4 made the check-in conversation work end to end: occurrences exist, the coach can
catch up the record, read the reasons back, and start the next experiment. What is left is
everything that makes that record legible *outside* the chat, plus the write tools that stop a
loop's action layer being frozen at creation.

This spec covers build steps 5–8 of the brief:

| Step | Deliverable |
| --- | --- |
| 5 | Screens: outcome history, strategy timeline |
| 6 | `add-action` / `update-action` / `remove-action`, `update-loop` |
| 7 | Screen: in-app catch-up list |
| 8 | `log-note` |

Order is deliberately **6 and 8 before 5 and 7**. The tools are thin and independently useful;
the screens are the larger piece, and two of them want to render what the tools write.

## What already exists

Worth stating so the plan does not rebuild it:

- `resources/js/patyourself/strategy-timeline.tsx` renders the version history already — but only
  version, intervention point, approach, change reason and superseded reason. It shows **none** of
  the experiment framing that phase 2 added (`verdict`, `planned_days`, `day_of_experiment`,
  `is_under_review`), even though `StrategyResource` has been sending those keys since phase 2 and
  `StrategyData` already types them.
- `loops/show.tsx` is the loop record and already embeds that timeline.
- `RescheduleAction` handles schedule edits and now re-anchors `series_started_at`.
- `UpdateIntention` handles partial loop edits, including the paused → active re-anchoring.

So step 5 is largely *extension*, and step 6's `update-action` and `update-loop` mostly need a tool
boundary over Actions that already exist.

## Decisions

### Notes get their own table, not `summaries`

`summaries` holds a **rolling narrative**: `ProgressController` reads `latestSummary` and
`progress/show.tsx` renders it as one block of prose. Appending discrete observations there would
turn a single narrative into an accidental log and break that rendering.

A note is a different thing: an append-only observation, timestamped, attached to the loop and to
no action. New `notes` table — `intention_id`, `body`, `noted_at`. `summaries` keeps its (still
unwritten) purpose, and the `write-reflection` tool the phase-4 carry-forward describes stays
available as a separate future thing.

**Notes must be readable, not just writable.** The mistake this whole phase exists to correct is
that `log-action-outcome` wrote reasons nothing could read. So `log-note` ships with its notes
surfaced in `get-loop` and on the loop record screen in the same change — never a write-only field.

### `remove-action` archives, it never deletes

Occurrences hang off an action, and outcomes hang off occurrences. Deleting an action would
cascade away the evidence — exactly the history this app exists to keep. `remove-action` sets
`status = archived`, which already means "not live" everywhere: `MaterialiseOccurrences` skips
archived actions, `TodaysActions` only surfaces open ones, and `StartExperiment` already archives
the prior action when a new experiment begins. The tool's description says so plainly, so the coach
does not describe it to the user as a deletion.

### The outcome history lives on the loop record, not its own route

The reframe spec calls `/loops/{loop}` "the merged lab record". Splitting history onto a separate
route would mean reading the strategy timeline and the outcomes it produced on two different
screens — which is precisely the comparison the notebook exists to support.

Default to the most recent 30 entries with a plain "Show the full history" link
(`/loops/{loop}?history=all`). No pagination widget: at this data volume it would be furniture.

### The catch-up list is reachable, not insistent

A dedicated `/catch-up` screen, linked as a plain text link from the loops index header. Deliberately:

- **no count in the link, no badge, no red state** — the notebook never nags, and a backlog is not debt;
- **not a primary nav tab**, because a permanent tab makes the backlog a standing accusation;
- same 14-day default window as `pending-outcomes`, with an explicit control to reach further back.

Each row logs in place. The existing `POST /actions/{action}/logs` cannot serve this — it resolves
the *live* slot, so it would log today against an occasion from Tuesday. A new
`POST /occurrences/{occurrence}/logs` endpoint keys on the occasion, exactly as `log-outcome` does.

A failure still requires a reason in the UI, and the field is labelled as the user's own words —
the same rule the tool boundary enforces, for the same reason.

### `update-loop` may correct the chain, including the craving

The chain as first written is a hypothesis and is usually wrong about the craving. The tool takes
`cue`, `craving`, `response`, `reward`, `title`, `description` and `status`, and routes through
`UpdateIntention` so the paused → active re-anchoring behaviour is not duplicated at a second
boundary.

`status` allows activate / pause / archive. It does **not** allow `completed`: a loop is a
behaviour under change, and marking one "completed" is the kind of finish-line framing the notebook
avoids. `UpdateIntention` still accepts it from the web surface, which is out of scope to change here.

## Screens

### `/loops/{loop}` — the lab record

Gains, in order: the chain, the **strategy timeline with experiment framing**, the **outcome
history**, and the **notes**.

Timeline nodes additionally show:

- `day N` for a running experiment, and `day N of M` only when `planned_days` is set. A null
  `planned_days` means open-ended and must never render as a countdown or a zero-day experiment.
- the verdict when concluded, with its note.
- `outcomes_recorded` per version — this is what separates a version that **failed** from one that
  was **never tested**, and without it both read as "no evidence".
- counts as raw fractions (`8/11`), never a rounded rate, at these sample sizes.

Outcome history rows show: the occasion's date, the action title, the outcome, the **reason
verbatim**, the context, and which strategy version was running. Skips are visually neutral — they
are not failures and must not read as them.

### `/catch-up` — the in-app catch-up list

Materialises on load, then lists unlogged past occasions newest first, grouped by loop, each with
an inline log control. Empty state is a plain statement that there is nothing waiting, with no
congratulation — no gamification.

## Constraints

Unchanged and binding, from the brief:

- **Reasons verbatim** — no tidying in any UI path either.
- **Strategy versions append-only.**
- **No quantities on eating loops** — no numeric targets in any new field or copy.
- **Failure language is about the strategy, never the user** — this now includes UI copy, empty
  states and button labels.
- **No gamification** — no badges, levels or celebratory states. A streak is a statistic.
- **The notebook never nags** — no overdue counts, no red backlog states.

## Out of scope

`conclude-experiment` (and the open `ConcludeExperiment` / `review_at` decision it depends on),
`write-reflection` and the `summaries` writer, MCP prompts, the `/log` fast-capture screen, the
`/loops` index redesign, and the dashboard notebook reframe. The three open items from the phase 3/4
carry-forward (`laravel/ai`, `ANTHROPIC_API_KEY`, `ConcludeExperiment` clearing `review_at`) remain
open and untouched.
