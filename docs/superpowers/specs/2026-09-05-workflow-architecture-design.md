# Workflows — how modules plug into the loop

The app tracks whether you did the thing. A **workflow** is how it also records what happened while you
did it, without becoming a different app each time.

Gym is the first. Journalling, running and meal prep are the ones this design is tested against.

**The loop and its experiment stay the universal entry point.** A workflow never replaces them, never
gets its own scheduling, and never earns its own currency. It brings a recording surface and usually a
table or two, and inherits everything else.

---

## The frame

The alternative is what most apps in this space become: a habit tracker, and then a *separate* lifting
app, and then a *separate* run log, sharing a login and nothing else. Each one reinvents scheduling,
reminders, streak-or-not, and the question of what counts as done.

This app already answers all of that once, correctly, in a way that took months to get right —
occurrences, catch-up, the versioned experiment that never rewrites history, the reason recorded on
every failure. **A module that reimplements any of it is a bug in this design.**

## The spine, and what every workflow inherits

```
Intention  (loop)          workflow: 'gym' | 'journal' | null
 └─ Strategy               the plan — versioned, revising never rewrites history
     └─ Action             a recurring occasion type
         │                     ← EXTENSION POINT 1: configuration
         └─ Occurrence     one occasion, one row, already exists when due
             │                 ← EXTENSION POINT 2: the record
             └─ ActionLog  the verdict: done / missed / skipped + reason
```

Written once, inherited by every workflow, and **not to be re-solved**:

| Inherited | Where it already lives |
| --- | --- |
| Scheduling and what is due | `Occurrence` |
| Catch-up on missed occasions | the catch-up screen |
| Email reminders | per action |
| Versioned plans, with reasons | `Strategy` and its revisions |
| The verdict and its reason | `ActionLog` |
| Logging from outside the app | the MCP write surface |
| Blob | `logCount` / `insightCount` |

## Two extension points, and only two

**1. Configuration on an Action** — what this occasion is *meant* to contain.
**2. A record on an Occurrence** — what it actually contained.

| Workflow | Config on Action | Record on Occurrence |
| --- | --- | --- |
| `gym` | the exercise template — 3 × 10 bench, 3 × 10 row | the sets performed, with weights |
| `journal` | prompts, if any | the entry |
| `running` | target distance or duration, if any | the run — distance, time, pace |
| `mealprep` | the planned meals | what was actually cooked |

Both are **optional**. A journalling workflow with no prompts has no config, and that is not a special
case — it is an empty extension point.

Anything a workflow wants that fits neither is a signal to stop and re-read this document. It is
usually either an attempt to re-solve something in the inherited table above, or a second currency.

## The invariant

> **One occasion produces exactly one `ActionLog`, whatever the workflow recorded.**

A forty-set session, a thousand-word entry and a glass of water are worth the same to the record and to
Blob.

This is the rule the whole idea rests on. The moment a workflow can mint fuel faster by being more
granular, the app has to hold a position on what each module is *worth* — and every new module reopens
the argument. Refusing that once, here, settles it for everything that follows.

It has a consequence worth stating rather than discovering: **a hard training session is worth what
flossing is worth.** That is correct under this app's own premise — recording is the teaching, not
achievement — and it will still feel wrong on a leg day.

The corollary, and the guard that enforces it: **recording does not log.** Filling in every set, writing
the whole entry, importing the run — none of it creates an `ActionLog`. The verdict is pressed
separately, by a person, always.

## The registry

`workflow` is a **nullable string on `intentions`**, persisted, chosen from a known registry rather than
typed.

```
WORKFLOWS = {
    gym:      { config: exercise template, record: performed sets, ui: … },
    journal:  { … },
}
```

- **Null** is a plain loop. Done, missed, skipped — the app as it is today, and the overwhelming
  majority of loops forever.
- **A known name** routes to that workflow's recording UI.
- **An unknown name falls back to the plain UI.** It never blanks the screen.

That last rule is not new. `sceneFor()`, `ROOM_OBJECTS`, `SPRITE_ITEMS` and `ANIMATIONS` all already
hold it — *naming a thing the registry does not know must never be able to break the screen* — and
`scenes.ts` also records the trap: use `Object.hasOwn`, because a bare lookup walks the prototype chain
and `'constructor'` resolves to something truthy.

### Why a registry and not a free-form tag

Tags were the earlier answer and they are a weak binding for a contract. A tag is typed: `Gym`,
`gimnasio`, or a trailing space, and the routing silently fails with nothing on screen to explain why.
A registry name is chosen, spelled by the code, and testable.

### Why persisted, and not derived from "does it have exercises"

Two reasons, and the second is the one that matters later.

**It has to exist before the data does.** If the recording UI only appears once a routine exists,
nothing ever offers you the chance to build one. An earlier draft of the gym spec had exactly this
rule; it was self-consistent and unimplementable.

**Analytics needs it stored.** Asking *how do my gym loops compare to my journalling loops* should be a
`group by workflow`, not an inference from which tables happen to have rows. Deriving it would mean
every future question re-implements the same guess.

### Why this is not the `kind` column that was rejected

It is close, and the difference is what it claims. `kind: habit | training` describes **what a loop
is**, and can contradict its own data — a loop marked training with no routine. `workflow: 'gym'`
describes **which recording surface this loop uses**: intent, which is allowed to exist before there is
anything to record, and which nothing can contradict because it asserts nothing about state.

One per loop. A loop has one response — one behaviour — so it has one way of being recorded. A loop
that is genuinely two behaviours is two loops.

## What a new workflow must provide

1. A registry entry: name, and what it attaches at each extension point.
2. Its tables — config keyed to `actions`, record keyed to `occurrences`.
3. A recording UI, and a setup UI for its config.
4. A one-line answer to **"what is one occasion here?"** — one gym session, one journal entry, one run.
   If that answer is unclear, the workflow is not ready to be built.
5. Its source files added to `CompanionVocabularyTest::sourceFiles()`.

## What a workflow may not do

- **Create or count its own logs.** One occasion, one `ActionLog`.
- **Add a counter Blob reads.** `logCount` and `insightCount` are the whole economy.
- **Schedule anything.** Occurrences already exist and already know what is due.
- **Write its own catch-up, reminders or streak logic.** All inherited, all already correct.
- **Grade the person.** The vocabulary test is not negotiable per module.

## Tested against three more

The abstraction was designed on gym, which is exactly how a plug-in system ends up fitting one plug-in.
Sketched here — not designed — to find out whether it is gym-shaped.

**Journalling.** Loop "write in the morning", strategy "3 mornings a week for a month", action "morning
pages", record is the entry. Config is empty unless prompts are wanted. **Fits.** One thing to check
when it is designed: `Note` and the reflection writer already exist, and journalling may belong with
them rather than as a new table.

**Meal prep.** Loop "prep lunches on Sunday", config is the planned meals, record is what was actually
cooked. **Fits**, and it is the plainest instance of both extension points.

**Running.** Loop "run three times a week", action "easy run" / "long run", record is the run itself.
**Fits, and it stresses the design in one place** — the record arrives from Strava rather than from
someone tapping. That gives workflows a second dimension:

| | Record originates | Examples |
| --- | --- | --- |
| **Entered** | a person, in the app | gym, journal, meal prep |
| **Imported** | an external service | running, cycling, weight |

An imported workflow needs two things the entered ones do not: a rule matching an arriving activity to
an occurrence (by date, in practice), and a decision about whether an import may press the verdict on
your behalf.

**It may not.** Importing a run records the run; you still say whether the occasion counts. That keeps
the invariant intact — a service cannot mint Blob's fuel — and it keeps the reason field meaningful,
because "I ran but it was rubbish and I cut it short" is exactly the kind of thing this app exists to
capture and Strava cannot know.

So the abstraction survives all four. The import dimension is a genuine addition and is recorded here
rather than discovered when running is built.

## Error handling

- An unknown `workflow` value renders the plain UI. Never a blank screen, never an error.
- A null `workflow` is the normal case, not a missing value.
- A workflow whose config is absent records fine — config is optional at both points.
- Deleting a workflow's config leaves its records intact. Nothing in this app rewrites history.
- An imported record with no matching occurrence creates one, rather than being dropped.
- **Open question, must settle before gym is built.** The rule above answers this for an
  *imported* record. It has no answer yet for an *entered* one, and gym is plausibly
  cue-anchored ("after work"), so it hits the gap on day one: a record keys to an
  `Occurrence`, a cue-anchored action has none until the verdict creates one, and a
  recording surface handed `occurrenceId: null` has nowhere to write — nor can it write
  *before* the verdict, which is the whole premise of "recording does not log." Two
  candidate answers, neither chosen here: **(a)** the seam materialises an occurrence on
  demand when recording begins, mirroring the import rule above, or **(b)** workflows are
  documented as scheduled-only, so a cue-anchored loop simply carries no workflow. Either
  way, materialising-on-record must not create an `ActionLog` — the one-occasion-one-log
  invariant holds under both answers, so it is not what decides between them.

## Testing

- A loop with `workflow: null` behaves **exactly** as loops do today. This is the regression that
  matters: the claim is that nothing was special-cased, and it is only true if it is pinned.
- An unknown workflow name falls back to the plain UI, proven by naming one that does not exist —
  including `'constructor'`, because the prototype-chain trap has already been found once in this
  codebase.
- **Recording at either extension point moves `logCount` by zero.** The single most important guard
  here; every future workflow must add its own version of it.
- `workflow` survives a strategy revision — the plan changes, the routing does not.
- Blob's counts move identically for a workflow log and a plain one.

## Out of scope

- **Any workflow's own design.** Gym has a spec; the rest have sketches above and nothing more.
- **A plug-in API for third parties.** These are modules in one codebase, not an extension system.
- **Multiple workflows per loop.** One behaviour, one recording surface.
- **Migrating existing loops.** They have `workflow: null` and carry on unchanged.
- **Analytics itself.** This makes it possible by persisting the identifier; the views are their own
  project.

## Assumptions

- Single user in practice, so `workflow` needs no per-user registry.
- Workflows are added by editing this codebase, not at runtime.
- Every workflow can answer "what is one occasion here?" in one line. If it cannot, it is either two
  workflows or it is not one.
