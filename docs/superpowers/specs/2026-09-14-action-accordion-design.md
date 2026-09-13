# Collapsing the action layer

**Date:** 2026-09-14
**Status:** design agreed, not yet implemented
**Scope:** the action layer on the loop screen, and the client workflow registry's config slot.

The first of three specs on one branch. The second adds a date to the action
editor's schedule; the third adds fortnightly and monthly recurrences. This one
lands first because it restructures the rows the other two write into, and it
changes no semantics at all.

---

## 1. The problem

Every action on the loop screen renders its whole routine inline. A training
loop with three gym sessions is three titles, three cadence lines, six buttons
and fifteen exercise rows in one flat list, and the thing the screen is for —
seeing which sessions this loop runs — is buried in the thing it records.

A loop with no workflow does not have this problem. Its action rows are one line
each and read correctly today.

## 2. The disclosure belongs to the host, not the module

`ActionLayer` renders a generic `<WorkflowConfig>` slot; gym's `RoutineEditor`
is what that slot resolves to. Collapsing inside `RoutineEditor` would make
disclosure a gym feature that journalling has to reinvent. Collapsing in the
host makes it a property of the config site.

That is the right line. `docs/WORKFLOWS.md` §3 draws it already: the config site
is **what an occasion is meant to contain** — a standing prescription, reference
material, read far less often than it is scrolled past. The record site is the
opposite, one line that gets a person into a session, and **must not** be
collapsed. So the change is scoped to the config host and the action layer
around it. `workflow-record.tsx` is untouched.

## 3. The unit of disclosure is the action, not the routine

Two placements were considered:

| | Summary | Body |
| --- | --- | --- |
| **Rejected** | the action row, unchanged | a "Routine" disclosure underneath it |
| **Chosen** | the action's title and cadence | `Edit` / `Retire`, then the routine |

The rejected one is a smaller change and keeps `Edit` and `Retire` one tap away,
but it leaves the list exactly as tall as it is now — three action rows, each
with its own nested triangle. The screen's problem is its height.

```
▸ Upper body 2 · weekly at 07:30
▸ Lower body · weekly at 07:30
▾ Pull day · weekly at 07:30
      Edit   Retire
      Barbell Row      3 x 10   ↑ ↓   Remove
      Lat Pulldown     3 x 12   ↑ ↓   Remove
      ▸ Add an exercise
```

**`Edit` and `Retire` move into the body.** Not a preference: `Retire` is a
submit button inside an Inertia `<Form>`, and a button inside `<summary>` both
submits the form and toggles the disclosure on one click. Suppressing the toggle
means intercepting the event, and a `<form>` inside `<summary>` is doubtful
markup besides. Both controls are rare and neither is urgent, so one extra tap
to reach them is the cheaper cost.

## 4. Which actions collapse

**Only actions that have something to disclose.** A plain loop's action row is
correct today and must not grow a triangle with nothing behind it.

The test is not `workflow !== null`. `WorkflowConfig` already computes the exact
condition and throws it away:

```tsx
if (spec === null || spec.config === null || rows === null) {
    return null;
}
```

Three ways to draw nothing: no workflow or an unknown name, a workflow with an
empty config site, and a loop that does not configure actions. `ActionLayer`
needs the same answer before it can decide the row's shape, so the rule is
extracted rather than restated:

```ts
// resources/js/patyourself/workflows.ts
export function configuresActions(
    workflow: string | null | undefined,
    rows: WorkflowConfigRow[] | null,
    registry: WorkflowRegistry = WORKFLOWS,
): boolean
```

`WorkflowConfig` then calls it instead of holding its own copy, so the host and
the slot cannot drift into disagreeing about whether a surface exists — the
failure that disagreement produces is a collapsed action whose body is empty,
with `Edit` and `Retire` hidden behind a triangle that reveals nothing.

**`rows: null` and `rows: []` stay distinct**, as `docs/WORKFLOWS.md` §8 rules.
`null` is "this loop has no configuration surface" and collapses nothing; `[]`
is "this action's routine is empty" and collapses to a body reading
"No exercises on this one yet." with the add control under it. An empty routine
is still a routine.

**`ActionLayer` gains an optional `registry` prop**, defaulting to the real one,
mirroring `WorkflowConfig`. Without it a test cannot exercise the collapsed
shape against anything but gym, and the second module would be written blind.

### The one case that degrades

A workflow registered on the server and absent from the client sends `rows` as
an array while `workflowFor()` returns null. `docs/WORKFLOWS.md` §11 already
records that state as "configurable and draws nothing, with the suite green".
Under this change it stays exactly that: `configuresActions()` is false, the row
renders flat, and `Edit` and `Retire` stay where they are. The degradation does
not get worse, which is the whole reason the predicate consults the registry
rather than trusting `rows` alone.

## 5. Many open at once

Plain `<details>`, no `name` attribute, no React state.

- Comparing two sessions' routines is an ordinary thing to want.
- `<details name="…">` would give exclusive opening with no JavaScript, but jsdom
  does not implement the grouping, so the behaviour would ship with no test able
  to assert it.
- Holding the open id in `ActionLayer` state is testable but puts state where
  the platform already has a control, and adds a second thing to reset.

It is also the idiom this screen already uses — "Add an action" and
"Add an exercise" are both `<details>` today.

**Closed on arrival.** The list is the point.

## 6. The disclosure does not know about the editor

Pressing `Edit` renders `ActionEditor` inside the body, above the routine. The
disclosure is not closed, not reopened and not disabled while editing: the
button that opened the editor is inside the body, so the body is necessarily
open already, and closing it would hide the form the tap just asked for.

Cancel and a successful save return the body to `Edit` / `Retire` and leave the
disclosure exactly as the user left it.

The existing `editing` state in `ActionLayer` is unchanged — still at most one
action in edit state at a time, as `docs/superpowers/specs/2026-09-13-amendable-action-layer-design.md`
§5 has it.

## 7. Files

| File | Change |
| --- | --- |
| `resources/js/patyourself/workflows.ts` | `configuresActions()` — new export |
| `resources/js/patyourself/workflow-config.tsx` | calls it instead of restating the condition |
| `resources/js/patyourself/loops/action-layer.tsx` | the collapsed row, the `registry` prop |
| `resources/js/patyourself/loops/action-layer.test.tsx` | new cases below |
| `resources/js/patyourself/workflow-config.test.tsx` | unchanged behaviour, re-asserted through the predicate |
| `docs/WORKFLOWS.md` | §6 gains the predicate; §3's table notes the config site is disclosed |
| `docs/GYM.md` | §5's "routine editor" paragraph notes it opens from the action |

No PHP. No migration. No new file, so nothing to add to
`CompanionVocabularyTest::sourceFiles()` — `action-layer.tsx` and
`routine-editor.tsx` are both already on it, and `workflows.ts` and
`workflow-config.tsx` are the registry and the slot, which carry no copy.

**Any new copy avoids** `streak`, `congratulation`, `well done`,
`completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`,
`misses you`, `neglect`, `cooldown`. `points` and `percent` are substring traps —
*endpoints* and *percentage* both trip them. Sentence case, no exclamation
marks. This spec adds no user-facing string beyond what the summary already
renders, which is the action's own title and its cadence.

## 8. Testing

**New JS:**

- A gym action renders collapsed: the routine's rows are in the document and not
  visible until the summary is opened.
- Opening the summary reveals the routine, `Edit`, `Retire` and the
  add-an-exercise control.
- Two actions can be open at the same time — opening the second does not close
  the first.
- A plain loop's action renders flat: no `<details>` around it, `Edit` and
  `Retire` visible without any interaction. This is the regression that matters
  most, and it is the client-side sibling of `PlainLoopIsUnchangedTest`.
- A loop whose workflow the client registry does not know renders flat, with its
  controls reachable, even though the server sent `rows`.
- `rows: []` still collapses, and its body says the routine is empty.
- `configuresActions()` directly: null name, unknown name, a registry entry with
  a null `config`, `rows: null`, `rows: []`.
- Pressing `Edit` inside an open body renders the editor and leaves the
  disclosure open.

**A risk to settle in the first task, not to assume.** jest-dom's `toBeVisible`
does account for a closed `<details>` ancestor, so the assertion above is sound.
Whether jsdom's `<summary>` click activation toggles `open` is the part to
verify first. If it does not, the tests drive `open` on the element directly and
say so in a comment, rather than asserting a toggle jsdom never performed.

**Verification:**

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Baseline to hold: **1028 PHP tests / 6533 assertions, 496 JS tests, 0 TypeScript
errors.** The PHP count does not move. The JS count rises. `npm run build` must
run first or `PwaManifestTest` skips itself and drops ~470 assertions.

`npm run lint` runs **inside the worktree**, never from the repository root:
eslint descends into `.claude/worktrees/` from there, reports thousands of
vendor errors and silently `--fix`es tracked files.

## 9. Out of scope

- **The record slot.** `WorkflowRecord` stays inline on the dashboard and
  catch-up cards. Its job is one line that reaches the session.
- **A count in the summary.** "Gym · 4" is cryptic and "4 exercises" is gym's
  noun, which the host does not have and would need a new registry field to
  learn. The triangle is the affordance; the count can be added with that field
  if a second module ever wants one.
- **Reordering or any other change to `RoutineEditor`.** The module's surface is
  unchanged; only what encloses it moves.
- **The add-an-action form**, which is already a `<details>` and stays one.
- **Nothing on the dashboard, catch-up or session screens.**
