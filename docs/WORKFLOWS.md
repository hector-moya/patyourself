# Workflows

A workflow is how the app records **what happened** during an occasion, on top of whether it happened
at all. It is the module architecture: the seam a new subsystem plugs into without touching the
notebook.

This is the current-state reference: how it works today, why it works that way, and which decisions
must not be reopened. The frozen design record is
`docs/superpowers/specs/2026-09-05-workflow-architecture-design.md`, accurate for the day it was
written. **Where this file and that spec disagree, this file is right.**

Read `docs/NOTEBOOK.md` first. This file assumes the intention → strategy → action → occurrence → log
chain and the `ResolvesOccasionSlot` two-ceiling rule. `gym` is the only registered workflow today;
`docs/GYM.md` walks it.

**This file is not on `CompanionVocabularyTest::sourceFiles()` and must not be added.** §9 states what
a module may not say, which cannot be written without naming the banned words, so scanning it would
fail on its own subject matter. `docs/BLOB.md` carries the same exemption for the same reason.

---

## 1. The one sentence

**A workflow brings a recording surface and nothing else.**

The loop, its experiment, its schedule, its cards and its verdict are unchanged by one. A loop with no
workflow — every loop today apart from a training loop, and the ordinary case forever — reaches
nothing here and keeps the plain screen it has always had.

## 2. What a workflow is not

| Not | Because |
| --- | --- |
| A second kind of loop | One behaviour is one loop. Three gym sessions a week are three *actions* on one loop |
| A second kind of log | One occasion produces exactly one `ActionLog`, workflow or not |
| Something a user types | A workflow is spelled by `config/workflows.php`. That is the whole difference between this and the free-form tag it replaced |
| A branch in the notebook | Nothing in `LogAction`, `TodaysOccasions` or the ladder asks which workflow a loop names |

## 3. The four extension sites

A workflow name lives in one column — `intentions.workflow` — and reaches **two sites on each side of
the wire**. Two mirrored registries, two slots each.

```
                    intentions.workflow  ('gym' | null)
                               │
        ┌──────────────────────┴──────────────────────┐
        │                                             │
   SERVER                                        CLIENT
   config/workflows.php                          resources/js/patyourself/workflows.ts
   → WorkflowRegistry → WorkflowDefinition       → WORKFLOWS → workflowFor()
        │                                             │
   ┌────┴────┐                                   ┌────┴────┐
 config    record                             config    record
   │          │                                   │         │
 keyed to   keyed to                          <WorkflowConfig>  <WorkflowRecord>
 actions   occurrences                         in the action     above the verdict
                                               layer            controls
```

| Site | Keyed to | Means |
| --- | --- | --- |
| **`config`** | `actions` | What an occasion is **meant to** contain — the standing prescription |
| **`record`** | `occurrences` | What it **actually** contained — a fact about one occasion |

That line is drawn identically on both sides, and it is the same line `docs/NOTEBOOK.md` §2 draws
between a prescription and an occasion. A configuration belongs to the action because it is part of
the standing prescription; a record belongs to the occasion because it is a fact about that one time.

**Either slot may be null.** A workflow that attaches nothing at a site is not a special case; it is
an empty site.

**The two registries are mirrored, not shared.** `config/workflows.php` decides what a name may be
*set to* — it is what `update-loop`'s enum is built from. `workflows.ts` decides only what gets
*drawn*. Adding a module means editing both. Nothing enforces that they agree: a name registered on
the server and absent from the client is a loop that can be configured and draws nothing, with the
suite green.

## 4. The server side

```php
// config/workflows.php
'registry' => [
    'gym' => [
        'label' => 'Gym',
        'config' => ActionExercise::class,   // keyed to actions
        'record' => PerformedSet::class,     // keyed to occurrences
    ],
],
```

`Services\Workflows\WorkflowRegistry` reads it — `all()`, `names()`, `has()`, `for()` — and returns
`WorkflowDefinition` value objects.

Three properties of that class are deliberate:

**An unknown name and a null name both resolve to "no workflow".** Naming a workflow the registry
does not know must never break a screen — the same rule scenes, room objects and animations already
follow.

**The whole array is read and matched with `array_key_exists`, never asked for by dot path.**
`config('workflows.registry.'.$name)` walks *into* an entry whenever the name contains a dot: a loop
naming `gym.label` would be handed back the string `'Gym'`, which is truthy and so never triggers a
fallback, and the caller would then read `->record` off it. That is the same shape as the
prototype-chain trap `scenes.ts` records, arriving by a different route.

**Config is read on every call rather than cached on the instance,** so a workflow registered after
the service was resolved is still found — which is what lets a test register one.

## 5. Materialising the occasion

`Services\Workflows\MaterialisesOccasion` is the one piece of the seam that is not a registry.

A record keys to an `Occurrence`, and a cue-anchored action has no schedule, so it has produced none —
pressing a verdict is what creates one. But records are written **during** the occasion, long before
anyone presses a verdict. So beginning to record has to materialise the occasion first.

```php
MaterialisesOccasion::forAction(Action $action): Occurrence
```

It delegates to `ResolvesOccasionSlot::todaysSlotFor()` — today's slot, **due or not yet due** — which
is the sibling of the method the logging flow uses, not a second implementation of it. See
`docs/NOTEBOOK.md` §4 for why the two ceilings differ and what the 18:00-for-a-19:00-slot case costs.

Three rules, all load-bearing:

**Materialising must not create an `ActionLog`.** The occasion now exists to hang records on; the
verdict is still pressed separately, by a person, afterwards. One occasion, one log, unchanged.
`logCount` is what the companion ladder spends, so a workflow that could mint a log by being more
granular than "one occasion" would inflate the economy every other loop is measured against.

**A recording surface must hold the occurrence it opened with and hand it back to `LogAction`.** The
fourth parameter. Left null, the verdict resolves itself afresh at the moment it is pressed — a
different question at a different time, and possibly a different answer. Pinned by
`Tests\Feature\Workflows\MaterialisesOccasionTest::test_the_verdict_lands_on_the_session_only_when_the_caller_names_it`.

**A session begun and abandoned leaves an unlogged occasion,** which is indistinguishable from any
other occasion nobody got to, and correctly ends up on `/catch-up`.

Nothing in this class is gym-specific. Journalling or running reaches for the same rule rather than
writing a second copy of it.

## 6. The client side

```ts
// resources/js/patyourself/workflows.ts
export const WORKFLOWS: WorkflowRegistry = {
    gym: { name: 'gym', label: 'Gym', config: RoutineEditor, record: GymRecord },
};

export function workflowFor(name, registry = WORKFLOWS): WorkflowSpec | null
```

Two host components resolve a name to a component and render it:

| Host | Mounted in | Passes |
| --- | --- | --- |
| `<WorkflowConfig>` | `patyourself/loops/action-layer.tsx` (the loop screen) | `actionId`, `rows` |
| `<WorkflowRecord>` | `pages/dashboard.tsx`, `pages/catch-up.tsx` | `occurrenceId`, `actionId`, `onOccurrenceMaterialised` |

Four properties to preserve:

**`Object.hasOwn`, never a bare lookup.** A plain object's lookup walks the prototype chain, so a name
like `'constructor'` or `'toString'` resolves to an inherited `Object` value that is truthy and
therefore never triggers the fallback — the failure then surfaces further down where the caller reads
`.record` off what it thinks is a `WorkflowSpec`. `scenes.ts` records the same trap; this is the second
registry to hold the rule.

**Both hosts wrap the resolved surface in an error boundary whose fallback is "draw nothing".** A
registered module that throws while rendering degrades to the plain screen instead of taking the
verdict controls or the action layer down with it. Class components, because React exposes error
boundaries only through `static getDerivedStateFromError`.

**Both boundaries are keyed on `workflow`.** Without a key tied to what they guard, React reuses the
instance across re-renders at that position and `hasThrown` never clears — the boundary stays latched
even once a different, working workflow occupies the position, which looks identical to the deliberate
"plain loop draws nothing" fallback while meaning the opposite thing.

**Both slots render *outside* the form beside them** — `WorkflowRecord` outside the verdict form,
`WorkflowConfig` outside the add-an-action form. Recording is not logging and configuring is its own
act; inputs living inside those forms would submit with them and quietly join the two.

One prop needs care. `WorkflowConfigSlotProps.rows` is `WorkflowConfigRow[] | null`, and **null and
`[]` mean different things** — "this loop has no configuration surface" against "this action's
routine is empty". The server sends them apart for exactly that reason.

And `onOccurrenceMaterialised` exists because the `occurrenceId` prop goes stale the instant the
surface materialises one: it came from the server render that *preceded* materialising. The host holds
the new id in state and keeps its own logging endpoint pointed at it. Reading the prop straight through
would send the verdict to the action route's own live slot instead of the occasion the surface actually
recorded against.

## 7. Authoring the second module

There is a working example to copy that ships nothing:
`tests/Fixtures/Workflows/{RegistersSpecFakeWorkflow,SpecFakeConfig,SpecFakeRecord}.php` plugs a fake
workflow into the registry with real tables at both sites, against the in-memory test database.

### What you do not have to touch

The loop, the strategy, the experiment, the verdict, the schedule, the cards, `LogAction`,
`TodaysOccasions`, the companion ladder, or the plain screen. If a step below asks you to change one
of those, the design is wrong, not the seam.

### The steps

1. **Decide what attaches where.** One question per site: what is this occasion *meant to* contain
   (`config`, keyed to `actions`), and what did it *actually* contain (`record`, keyed to
   `occurrences`)? Either may be null. A journal has a record and arguably no config; a reading
   target has a config and a record.

2. **Migrations.** One table per non-null site. The config table carries `action_id`; the record table
   carries `occurrence_id`. Both `cascadeOnDelete`. Add a unique index over whatever tuple makes a
   duplicate meaningless — gym uses `(action_id, position)` and
   `(occurrence_id, exercise_id, set_number)`.

3. **Models.** Plain Eloquent, `#[Fillable]`, a `belongsTo` back to `Action` or `Occurrence`. The
   docblock says which site it is. Nothing more: the registry points at the class name.

4. **Register on the server** — one entry in `config/workflows.php` with `label`, `config`, `record`.
   This is what makes the name choosable and what `update-loop`'s enum is built from.

5. **Writers, as Actions in `app/Actions/<Module>/`.** One act each, and the only place that table is
   written. If the write computes a position or a sequence number, read the max with
   `lockForUpdate()` inside a transaction — two quick taps otherwise both compute the same next value
   and race into the unique index, which surfaces as a 500 on the second one. Use `max(...) + 1`, not
   a count: a count is only the next open slot while positions happen to be contiguous.

6. **Read models, as Services in `app/Services/<Module>/`.** Pure reads, a constant number of queries
   whatever the length. Load related rows in full — **never** `->with('rel:id,name')`, which returns
   null for a column added later with the suite green. Scope by the owning user through
   `intentions.user_id` if the module touches anything shared. Give every `ORDER BY` a tiebreaker on
   an id.

7. **Controllers and routes.** A record surface that can begin a session needs a materialise seam —
   copy `Training\SessionController::materialise`, which calls `MaterialisesOccasion` and nothing
   else. Keep it separate from the log controller. That separation *is* the "materialising must not
   log" rule.

8. **Client components.** One implementing `WorkflowConfigProps`, one implementing
   `WorkflowRecordProps`. The record surface must call `onOccurrenceMaterialised` when it materialises
   an occasion, and must not submit anything alongside the verdict.

9. **Register on the client** — one entry in `WORKFLOWS` in `workflows.ts`, pointing at those two
   components.

10. **Add every new file to `CompanionVocabularyTest::sourceFiles()`.** PHP and TypeScript, writers,
    read models, controllers, requests, components, and any README. **A file absent from that list is
    scanned by nothing, and this project has been bitten by exactly that.** Do not add a doc that
    quotes the banned words in order to explain them — see the header of this file.

11. **Tests.** Copy the fixture pattern for the seam itself, and pin at minimum: a plain loop is
    unchanged (`PlainLoopIsUnchangedTest`), an unknown name falls back (`WorkflowRegistryTest`), and
    the verdict lands on the session only when the caller names the occurrence
    (`MaterialisesOccasionTest`).

### The three obligations it is easiest to miss

- The **client** registry, after doing the server one. Server-only means configurable and invisible.
- The **vocabulary list**. Silent, and the failure is user-facing copy.
- **Handing the occurrence back to `LogAction`.** Silent, and it splits the record from the verdict.

## 8. Rulings that must not be reopened

| Ruling | Why | Cost if wrong |
| --- | --- | --- |
| **A workflow is spelled by config, never typed by a user** | It is the whole difference between this and the free-form tag it replaced | Unvalidated names reaching a `->record` read |
| **An unknown name resolves to "no workflow"** | Naming a workflow the registry does not know must never break a screen | A broken screen for a typo |
| **`array_key_exists` on the whole array, never a dot path** | A dotted name walks into an entry and returns a truthy string that never triggers the fallback | `->record` on a string |
| **`Object.hasOwn` on the client, never a bare lookup** | Prototype-chain names resolve to truthy inherited values | The same failure, one language over |
| **Both boundaries are keyed on `workflow`** | An unkeyed boundary latches forever and is indistinguishable from the deliberate fallback | A working module silently drawing nothing |
| **Materialising never creates an `ActionLog`** | One occasion, one log. `logCount` is the ladder's currency | An inflated economy every other loop is measured against |
| **The record slot renders outside the verdict form** | Recording is not logging | Records submitting with the verdict, joining the two |
| **`config` keys to actions, `record` keys to occurrences** | It is the prescription/occasion line the whole notebook already draws | A prescription dated by one occasion, or a record that outlives its occasion |
| **`rows: null` and `rows: []` stay distinct** | "No configuration surface" is not "an empty routine" | An empty editor on a loop that has no editor |

## 9. What a module may not say

Every workflow file goes on the vocabulary list, so a module inherits the companion's copy rules over
its own surfaces:

- No `streak`, `completion rate`, `percent`, `points`, `level up`, `cooldown` — comments included.
  `points` and `percent` are substring traps: *endpoints* and *percentage* both trip them.
- Sentence case. No exclamation marks. Never congratulating.
- **Never score what was recorded against what was configured.** A routine is a note of intent, not a
  target to be marked against. The config site exists so a person can write down what they meant to
  do; quoting it back as a fraction turns recording into being marked, and the recording surface is
  exactly where that temptation is strongest.

`/progress` is deliberately *off* the vocabulary list and a module's screens are deliberately *on*
it. `docs/NOTEBOOK.md` §9 has the boundary.

## 10. Where everything lives

```
config/workflows.php                          the server registry
app/Services/Workflows/
  WorkflowRegistry.php                        name -> definition, with the fallback
  WorkflowDefinition.php                      one entry: name, label, config, record
  MaterialisesOccasion.php                    the occasion a record hangs on

resources/js/patyourself/
  workflows.ts                                the client registry, the prop contracts
  workflow-config.tsx                         the config slot + its boundary
  workflow-record.tsx                         the record slot + its boundary
  loops/action-layer.tsx                      mounts the config slot
resources/js/pages/dashboard.tsx              mounts the record slot
resources/js/pages/catch-up.tsx               mounts the record slot

tests/Feature/Workflows/
  WorkflowRegistryTest.php                    resolution and the fallback
  WorkflowColumnTest.php                      intentions.workflow
  WorkflowInvariantTest.php                   what a workflow may not change
  PlainLoopIsUnchangedTest.php                the regression that matters most
  MaterialisesOccasionTest.php                the occurrence contract
tests/Fixtures/Workflows/                     a second module, in test scope only
tests/Feature/Training/GymWorkflowRegistryTest.php
```

## 11. What is not done

- **`gym` is the only entry.** Journalling is the expected second module and has not been designed.
- **Nothing checks that the two registries agree.** A name on the server and absent from the client
  is configurable and draws nothing, with the suite green. A test asserting the two key sets match
  would close it.
- **`create-loop` cannot set a workflow** — it routes through the `AuthoredIntention` DTO, which has
  no `workflow` property. `update-loop` can. See `docs/MCP.md` §6.
- **A workflow cannot attach anything to a strategy version.** Both sites key below it. Whether a
  module should be able to is an open question, not a missing feature.
- **The MCP gym tools are merged and pushed but not yet deployed,** so the live connector cannot
  configure a routine. See `docs/DEPLOY-FORGE.md` §14.
