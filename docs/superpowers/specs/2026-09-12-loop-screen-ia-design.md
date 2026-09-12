# Loop screen information architecture

**Date:** 2026-09-12
**Status:** design agreed, not yet implemented
**Scope:** `resources/js/pages/loops/show.tsx` and the components it composes. Frontend only.

---

## 1. The problem

The loop screen renders fourteen blocks in a flat column with no weighting. Opening it, the
first substantial thing on screen is a verdict form asking whether the experiment worked — a
question that is only live at the end of an experiment's life. Below it, the habit anatomy
spends roughly a screen and a half on four cards describing content that changes perhaps twice
in a loop's lifetime. The experiment itself — the thing the screen exists to show — is split in
two and neither half reads as the subject.

Current order, top to bottom:

| # | Block | Frequency of relevance |
| --- | --- | --- |
| 1 | Type / status chips | identity |
| 2 | Activate form (paused loops only) | once |
| 3 | Description | identity |
| 4 | `ExperimentHeader` — `v1 · cue`, `DAY 1 · OPEN-ENDED` | every visit |
| 5 | `ConcludeExperimentForm` — the verdict | end of an experiment |
| 6 | `Reflection` — what the record shows | every visit |
| 7 | `Anatomy` — four large stage cards | rarely |
| 8 | `StrategyTimeline` — including the hypothesis prose | every visit |
| 9 | `ActionLayer` — actions, routines, add, retire | often |
| 10 | `WorkflowPicker` — Recording | rarely |
| 11 | Start the next experiment | end of an experiment |
| 12 | `OutcomeHistory` | every visit |
| 13 | `NoteForm` | sometimes |
| 14 | `LoopNotes` | sometimes |

### Four specific defects

**The verdict is unconditional.** `ConcludeExperimentForm` already receives `isUnderReview` and
uses it only to swap the legend text between "This experiment has reached its review date." and
"Give this experiment a verdict." The full form — three options, a conditional note field and a
submit button — renders at position 5 either way. The signal needed to hide it already exists
and is already computed: `Strategy::isUnderReview()` on the server,
`ExperimentHeader::runState()` already returns `Ready for a verdict` from the same flag.

**The experiment is split.** `ExperimentHeader` at position 4 renders version, intervention point,
run state and the evidence line — but not the hypothesis. The hypothesis (`StrategyData.approach`)
renders at position 8, inside `StrategyTimeline`, below the anatomy. So the top of the screen
says *what version* without saying *what is being tried*, and the substance sits a screen and a
half down among superseded versions. Neither half is the protagonist.

**The anatomy dominates.** Four bordered cards, a numbered connector rail and a "strategy acts
here" badge, for `cue`, `craving`, `response` and `reward` — content the loop is defined by and
which almost never changes after authoring.

**Actions are ninth.** Tending actions — adding an exercise, retiring one, checking a cadence — is
among the more frequent reasons to open this screen, and it sits below everything.
`ActionLayer` is self-contained (it already holds its own "Add an action" and the retiring
helper text); what separates it from its neighbours is that `WorkflowPicker` and "Start the next
experiment" trail *after* it, so the block does not read as a unit.

## 2. What this screen is for

**The loop screen is where you read what is being tested and tend the actions that carry it. It
is not where a daily outcome is recorded.**

Recording happens on `/dashboard` (Today), `/catch-up`, and the gym session screen. The existing
docblock on `show.tsx` states this — *"outcomes are logged from the catch-up screen or the
conversation"* — and `OutcomeHistory` here is read-only.

Actions are promoted because tending them is frequent, **not** because logging happens here. No
logging controls are added to this screen. That was considered and rejected: it would duplicate
the dashboard's logging surface and put the one-occasion-one-log rule under two owners.

## 3. Constraint carried forward

From `show.tsx`'s docblock, and preserved by this design:

> The timeline and the history sit on one screen deliberately — comparing what was tried against
> what happened is the whole point of a notebook.

This rules out any tab scheme separating "what was tried" from "what happened". Past experiments
and outcome history stay adjacent, in that order. A tabbed layout was considered for exactly the
reason the anatomy is being demoted, and rejected: it would add a second navigation strip above
an existing bottom nav on a phone, and the obvious split violates the ruling above.

## 4. The new order

| # | Block | Change |
| --- | --- | --- |
| 1 | Identity — chips, description, activate form when paused | tightened; content unchanged |
| 2 | **Experiment card** | **new** — wraps `ExperimentHeader`, adds the hypothesis and the verdict slot |
| 3 | **Actions** | promoted from 9th |
| 4 | **The chain** — one line, expanding to the full anatomy | was ~1.5 screens |
| 5 | Reflection — what the record shows | unchanged, no longer competing at the top |
| 6 | Past experiments | `StrategyTimeline` minus the active version; renamed |
| 7 | Outcome history | unchanged; stays adjacent to 6 |
| 8 | Notes — form and list under one heading | were two unheaded siblings |
| 9 | **Loop settings** — one disclosure | collects Recording, Start the next experiment, End this experiment early |

### 4.1 The experiment card

A bordered card, so the experiment reads as the subject of the page:

```
┌─ v1 · cue ──────────────── Day 1 · open-ended ─┐
│ On gym days the clothes go in the bag and      │
│ leave the house with him in the morning. The   │
│ 3:30 cue only points at the gym if the kit is  │
│ already with him.                              │
│                                                │
│ 3 of 4 held · 75% · ▲ up from 60%              │   only when decided > 0
└────────────────────────────────────────────────┘
```

**It wraps `ExperimentHeader` rather than replacing it.** The header is the card's top row and
evidence line; the card supplies the border, the hypothesis prose and the verdict slot.
`experiment-header.tsx` and its thirteen test cases are untouched, and `runState()` stays the
single definition of how a run state is worded.

The hypothesis is `StrategyData.approach`, taken from the active unconcluded strategy that
`show.tsx` already computes as `activeExperiment`.

**Between experiments** (`current_version === null`), `ExperimentHeader` already renders
*"No experiment running · logging continues"*. The card keeps that and renders no hypothesis and
no verdict slot.

**The evidence line stays as it is today.** `3 of 4 held · 75% · ▲ up from 60%` is a completion
rate on a screen that is not `/progress`, and promoting it into a bordered card makes it louder
than it was. This was raised and the status quo was kept: removing it is a behaviour change
that was not asked for. Noted here so a later reader knows it was a decision rather than an
oversight. `pages/loops/show.tsx` is not on `CompanionVocabularyTest::sourceFiles()`; `/progress`
is likewise deliberately off it, and the reasoning is in `docs/NOTEBOOK.md` §9.

### 4.2 The verdict, conditioned

| State | Where the verdict lives |
| --- | --- |
| `is_under_review === true` | Inline, inside the experiment card, below the evidence line. Run state reads `Ready for a verdict` |
| otherwise | In Loop settings, as **End this experiment early** |

`ConcludeExperimentForm`'s internals do not change — same component, same
`strategies.verdict.store` route, same `isUnderReview` prop driving the legend. Only its mount
point moves, and it now has two.

Ending an experiment early stays reachable on purpose. The question is rarely the day's business;
it is not forbidden.

### 4.3 The chain

Collapsed to one tappable line:

```
cue → craving → response → reward                                              ▸
```

Expanding it reveals the existing four-card anatomy in place, unchanged. Implemented with
`<details>` / `<summary>` — the pattern this screen already uses for Recording and for Start the
next experiment — so no new interaction vocabulary is introduced and no modal focus management is
needed.

The collapsed line marks where the strategy acts, because that is the one fact the anatomy
carries that *does* change and it must survive the collapse. Concretely: the acting stage's word
is rendered in its own stage accent (`--stage-cue`, `--stage-craving`, `--stage-response`,
`--stage-reward`) at full weight; the other three are rendered muted. No badge and no extra text
— the line must stay one line. When the active strategy has no intervention point, all four
render muted.

`Anatomy` moves out of `show.tsx` into its own file. It is a 90-line presentational component
inside a 490-line page, and the page is being restructured around it.

### 4.4 Loop settings

One disclosure, holding the three rare and consequential controls that currently sit loose:

- **Recording** — the existing `WorkflowPicker`, itself a disclosure today. It becomes a plain
  labelled control inside this one rather than a nested disclosure.
- **Start the next experiment** — likewise unnested.
- **End this experiment early** — `ConcludeExperimentForm`, when not under review.

## 5. Files

| File | Change |
| --- | --- |
| `patyourself/loops/experiment-card.tsx` | **new** — wraps `ExperimentHeader`, adds hypothesis and verdict slot |
| `patyourself/loops/anatomy.tsx` | **new** — extracted verbatim from `show.tsx`, plus the collapsed chain summary |
| `patyourself/strategy-timeline.tsx` | exclude the active unconcluded version; heading → "Past experiments"; render nothing when the remainder is empty |
| `pages/loops/show.tsx` | becomes composition; drops the local `Anatomy`; gains the settings disclosure |
| `patyourself/experiment-header.tsx` | **unchanged** |
| `patyourself/loops/conclude-experiment-form.tsx` | **unchanged internals**; two mount points |
| `patyourself/loops/action-layer.tsx` | **unchanged** |

No server change. `StrategyData.approach`, `CurrentVersionData` and `is_under_review` are all
already sent — verified against `resources/js/patyourself/types.ts` and the existing
`activeExperiment` computation in `show.tsx`. No controller, resource, or migration edit.

## 6. Testing

### Existing tests this change breaks, and how each resolves

| Test in `pages/loops/show.test.tsx` | Resolution |
| --- | --- |
| `leads with the experiment and the reflection` | Rewrite. The experiment still leads; the reflection no longer follows it directly. Becomes an assertion about the new order |
| `offers a verdict for the active, unconcluded version` | Split into the two conditional cases below |
| `offers to start the next experiment behind a disclosure when a strategy is active` | Update — the disclosure is now nested inside Loop settings |
| the four `workflow picker` cases | Update for the same nesting; the picker's own behaviour is unchanged |
| `does not offer a verdict for a version already concluded` | Passes unchanged |
| `does not offer a verdict when no version is active` | Passes unchanged |
| `mounts the note form above the notes list` | Passes unchanged; that order is kept |
| the two `routine editor` cases | Unaffected |

### New assertions

- The verdict is absent from the page when the active version is not under review.
- The verdict renders inside the experiment card when the active version is under review.
- The verdict is reachable from Loop settings when not under review.
- The active version's hypothesis renders in the experiment card.
- Past experiments omits the active unconcluded version.
- Past experiments renders nothing when the active version is the only one.
- Actions precede the anatomy in DOM order.
- The chain is collapsed by default and names the intervention point.

`patyourself/experiment-header.test.tsx` should keep passing untouched. If it does not, the card
is doing more than wrapping.

### Verification

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline to hold or beat: 1015 PHP tests / 6380 assertions, 467 JS tests, 0 TypeScript errors.
The JS count will rise. `npm run build` must run first or `PwaManifestTest` skips itself and
silently drops ~470 assertions.

## 7. Out of scope

- **No logging controls on this screen.** §2.
- **No tabs.** §3.
- **No server change.** §5.
- **No change to `ActionLayer`, `ConcludeExperimentForm` internals, `OutcomeHistory`,
  `Reflection`, or the routine editor.**
- The gym session, dashboard and catch-up screens are untouched.

## 8. Open questions

None blocking. Two calls were made without an explicit answer and are recorded above rather than
left implicit: the evidence line stays (§4.1), and the reflection sits below Actions rather than
above (§4).
