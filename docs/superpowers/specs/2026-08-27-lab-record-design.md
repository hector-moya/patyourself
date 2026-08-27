# The lab record — making the experiment visible

Date: 2026-08-27
Status: designed

## Problem

The read model for experiments is built, tested, and rendered nowhere.

`LoopProgress` has three methods. Their callers:

| Method | What it answers | Called by |
| --- | --- | --- |
| `forLoop()` | The loop's lifetime record | `ProgressController` — the whole web UI |
| `forCurrentVersion()` | Is the experiment I am running now working? | `LoopProgressTool` — **Claude only** |
| `experimentsFor()` | Did v2 do better than v1? | **nothing** |

So Claude can see whether the current strategy is holding, and the owner cannot.
What the owner sees instead is a lifetime completion rate, which the service's
own docblock warns about:

> a fresh intervention drags the previous version's evidence forward and reads
> as though it had inherited its record

That is the gap. The app is a lab notebook whose experiments are invisible in it.

Three smaller truths have drifted alongside it:

- `progress/show.tsx` renders a section headed **"Coach summary"**, with the empty
  state *"Your coach hasn't summarized this loop yet."* The app went zero-LLM;
  there is no coach in it. The text is a reflection written through
  `write-reflection`.
- `write-reflection` records `window_start`, `window_end` and `events_count` —
  deliberately taken from the record rather than from Claude — and
  `ProgressController@show` passes only `->content`. The provenance that makes a
  reflection evidence rather than an assertion is dropped.
- The notebook screens do not use the notebook's design system.
  `patyourself.css` defines four accents named exactly `--cue`, `--craving`,
  `--response`, `--reward`; the Anatomy component paints its intervention point
  with `bg-primary` and the four sit unused.

## Scope

This spec covers the **lab record at `/loops/{loop}`** and the **`/loops` index**.

Out of scope, deferred to their own specs:

- The `/dashboard` notebook screen and the `/log` fast-capture screen.
- The `/progress` index (cross-loop). It survives this pass untouched; folding it
  away belongs with the dashboard.
- The landing page's chat-era pitch (`"A coach, not a tracker"`, *"patyourself
  coaches you through…"*). Stale, but rewriting the product pitch is the owner's
  call, not a side effect of a UI pass.

## Design

### 1. The experiment header

The first thing on `/loops/{loop}`, answering "what am I testing and how is it
going" before any scrolling. Fed by `LoopProgress::forCurrentVersion()`, which
already returns every field needed.

Four states, all first-class:

| State | Reads |
| --- | --- |
| Running, planned length | `v2 · craving · DAY 9 OF 14` |
| Running, open-ended | `v2 · craving · DAY 9 · OPEN-ENDED` |
| Past `review_at` | `v2 · craving · READY FOR A VERDICT` |
| No active version | `No experiment running · logging continues` |

`planned_days: null` means open-ended and must never render as a countdown, a
progress bar, or a zero-day experiment. The no-experiment state is stated plainly
and is **not** styled as a warning, an empty state, or a prompt to start one — a
loop running indefinitely with no experiment under test is a success.

### 2. Momentum, not scoring

Directly under the header, from the same call:

```
15 of the last 22 held            ▲ up from 41%
▪▪▫▪▪▪▫▪▪▪  last 10 occasions
```

Rules, binding:

- The comparison is **this version against the previous version**, not against a
  target. There is no goal number anywhere.
- The delta renders only when a previous concluded version exists and both have a
  non-null rate. Otherwise the line is omitted entirely — never "—" or "0%".
- A **falling** delta is stated in the same weight and colour as a rising one. It
  is information about the strategy, not a warning about the person.
- The streak is shown **only while it is running**: `9 in a row`. When it breaks
  it stops being rendered. No reset state, no "streak lost", no number falling to
  zero, no milestones, no celebratory moment. This is the whole of the
  gamification decision — momentum without a score that can be lost.
- `skipped` never enters a denominator. It is reported separately or not at all.

### 3. The experiment ladder

`StrategyTimeline` already renders versions, verdicts (`Did not hold`),
`ready to conclude`, `Day N of M` and `open-ended`, and its failure language is
already correct. It is **extended, not replaced**, and keeps its name, file and
tests — this spec adds to `strategy-timeline.test.tsx` rather than removing it.

What it gains, from `LoopProgress::experimentsFor()`:

```
EXPERIMENTS
v1  cue      concluded    9/22 held   41%    Did not hold
    "kept forgetting by evening"
    ↳ 3 reasons recorded

v2  craving  running      15/22 held  68%    Day 9 of 14
```

- Per-version totals and rate come from `experimentsFor()`, which attributes each
  log through `actions.strategy_id` and is already tested.
- Raw counts lead, the percentage follows. With a handful of logs a percentage
  hides its own denominator.
- `Not yet tested` stays the zero state — the difference between a strategy that
  failed and one that never ran is the difference the notebook exists to record.
- The failure reasons under a version are the user's own words, rendered
  verbatim. Never trimmed, squished or sentence-cased.

The section heading changes from "Strategy timeline" to **"Experiments"**. The
unit of the app is the experiment.

### 4. The reflection, with its provenance

Moves onto the lab record and gains the fields the controller currently drops:

```
WHAT THE RECORD SHOWS
Three of the last five breaks came after a skipped lunch. The
craving reads more like hunger than habit.

13–27 Aug · 28 occasions
```

- Heading: **"What the record shows"**. Retires "Coach summary".
- Empty state: **"No reflection written yet."** Retires *"Your coach hasn't
  summarized this loop yet."* Stated as a fact, not as something outstanding.
- The provenance line is `window_start`–`window_end` and `events_count`, in mono.
  It renders only when those values are present.
- The body is verbatim, `whitespace-pre-line`, never truncated.

### 5. The `/loops` index

Each row leads with experiment state, so "what am I running" is answered without
opening anything:

```
Evening snacking     v2 · craving     DAY 9 OF 14
Morning walk         v1 · cue         READY FOR A VERDICT
Reading              —                no experiment · logging
```

This needs `ActiveStrategySummary` (embedded in `IntentionResource`) to carry
`day_of_experiment`, `planned_days` and `is_under_review`. It currently carries
only `intervention_point`, `approach`, `rationale` and `version`.

The existing "Catch up" link stays plain text with no count. An unlogged occasion
never expires, and a number there would turn the record into a scoreboard.

### 6. `/progress/{intention}` redirects

Its content now lives on the lab record, so the route redirects to
`/loops/{intention}`. The route name survives; nothing 404s and no bookmark
breaks. `/progress` (the index) is untouched.

### 7. Typography and the stage accents

Two changes, both small and both semantic rather than decorative.

**Mono for what the record measured.** `Space Mono` is already loaded. Day
counts, totals, rates and the reflection window render in it; prose renders in
Hanken Grotesk. The split is the honest one: Claude wrote the sentences, the app
supplied the numbers.

**The Anatomy uses its own four colours.** `--cue`, `--craving`, `--response` and
`--reward` exist and are named for these exact four stages.

They cannot be reached as-is: they are scoped to `.py-shell, .py-landing,
.py-host`, and `CoachLayout` applies none of those. Adding `.py-host` to the
layout is **not** the fix — that block also defines `--border`, which collides
with the shadcn token the notebook screens already use for `border-border`.

Instead, expose the four under a non-colliding `--stage-*` namespace at `:root`
(both themes), and consume them in Anatomy. Nothing else in the cascade changes.

### 8. Dead chat-era CSS

Delete the 14 rules left from the chat UI, confirmed at `patyourself.css`
lines 218–219, 232–236, 240–243 and 294–296: `.py-avatar`, `.py-avatar--user`,
`.py-msg*`, `.py-typing*`, `.coach-head*`. Nothing renders them.

## Testing

Vitest, alongside the existing page tests:

- The experiment header renders each of the four states, and **open-ended never
  renders a countdown** — asserted by rendering `planned_days: null` and
  asserting the absence of "of".
- The momentum delta is **omitted**, not zeroed, when there is no prior version.
- A **falling** delta renders, and carries no warning styling or alarm word.
- The streak renders while running and is **absent** once broken.
- Per-version totals attribute correctly across a v1 → v2 boundary.
- A verbatim failure reason with irregular spacing and casing survives unchanged —
  the fixture input must arrive untidy, or the test proves nothing.
- The reflection renders its window and count, and omits the provenance line when
  they are null.
- The no-experiment state renders without warning styling.

PHPUnit:

- `IntentionController@show` passes `current_version`, `experiments` and the
  reflection with `window_start`, `window_end`, `events_count`.
- `IntentionResource`'s active-strategy summary carries the three new fields.
- `/progress/{intention}` redirects to `/loops/{intention}`.

**Every load-bearing assertion is mutation-verified**: change the implementation
so it should fail, run that single test, confirm it fails for the right reason,
restore. Non-discriminating fixtures are this codebase's recurring failure mode —
particularly the verbatim test, whose input must be untidy to mean anything.

## Constraints carried through

- Reasons, notes and reflections are **verbatim** everywhere, UI included.
- Failure language is about the strategy, never the user.
- No quantities on eating loops — no calories, portions, weights or numeric
  targets anywhere, including as a "goal" the rate is compared against.
- The notebook never nags: no overdue counts, no red backlog states, no prompt to
  start an experiment.
- No gamification beyond the momentum rules in §2, agreed explicitly with the
  owner: no badges, no levels, no milestones, no celebratory states, no streak
  reset.
- `skipped` = the occasion never happened. Never in a denominator.

## Assumptions

- `experimentsFor()` returns every outcome for every version. For a single-user
  app that is fine; if a loop's history grows large enough to matter, capping it
  is a resource-layer change and does not affect this design.
- The `/progress` index remains reachable and unchanged. Its nav entry is not
  re-pointed in this pass.
