# Action Accordion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collapse each action on the loop screen that has a configuration surface, so a training loop reads as a list of sessions rather than fifteen exercise rows.

**Architecture:** The disclosure belongs to the host, not the module — `ActionLayer` wraps the action in `<details>`, and gym's `RoutineEditor` is unchanged. Which actions collapse is decided by one resolver extracted from `WorkflowConfig`, so the host and the slot cannot disagree about whether a configuration surface exists. Plain `<details>`, no React state, many open at once.

**Tech Stack:** React 19, TypeScript, Inertia v3, Vitest + Testing Library + jest-dom, Tailwind v4.

**Spec:** `docs/superpowers/specs/2026-09-14-action-accordion-design.md`

## Global Constraints

- **Frontend only.** No PHP, no migration, no model change. The PHP suite's count must not move: **1028 tests / 6533 assertions**.
- **No new files**, so nothing joins `CompanionVocabularyTest::sourceFiles()`. `action-layer.tsx` and `routine-editor.tsx` are already on it.
- **Banned vocabulary, comments included:** `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown`. `points` and `percent` are substring traps — *endpoints* and *percentage* both trip them.
- **Copy rules:** sentence case, no exclamation marks, never congratulating, no second person keeping score.
- **`rows: null` and `rows: []` mean different things** — "this loop has no configuration surface" against "this action's routine is empty". Never collapse them together.
- **Run `npm run lint` from inside this worktree**, never from the repository root: eslint descends into `.claude/worktrees/`, reports thousands of vendor errors, and silently `--fix`es tracked files.
- **Verified environment facts, do not re-litigate:** jsdom's `<summary>` click toggles `details.open`, and jest-dom's `toBeVisible()` returns false for an element inside a closed `<details>`. Both were probed on this branch and both work.

## File Structure

| File | Responsibility |
| --- | --- |
| `resources/js/patyourself/workflows.ts` | The client registry, the prop contracts, and now the one resolver that answers "does this loop draw a configuration surface for this action?" |
| `resources/js/patyourself/workflow-config.tsx` | The config slot and its error boundary. Calls the resolver instead of restating its condition. |
| `resources/js/patyourself/loops/action-layer.tsx` | The action layer. Decides each row's shape from the resolver, and holds the two small presentational pieces the two shapes share. |
| `resources/js/patyourself/workflows.test.ts` | Registry resolution, including the new resolver. |
| `resources/js/patyourself/loops/action-layer.test.tsx` | Both row shapes, the disclosure's behaviour, and the plain-loop regression. |

---

### Task 1: One resolver for "is there a configuration surface here?"

`WorkflowConfig` already computes this and throws it away. `ActionLayer` needs the same answer before it can choose a row shape. Extract it so there is one copy.

It returns the resolved surface and rows rather than a boolean, because `WorkflowConfig` needs both narrowed and TypeScript cannot narrow `rows` through a boolean return. `ActionLayer` compares the result against null.

**Files:**
- Modify: `resources/js/patyourself/workflows.ts`
- Modify: `resources/js/patyourself/workflow-config.tsx:77-96`
- Test: `resources/js/patyourself/workflows.test.ts`
- Modify: `docs/WORKFLOWS.md` §6

**Interfaces:**
- Consumes: `workflowFor(name, registry)`, `WorkflowSpec`, `WorkflowConfigRow`, `WorkflowConfigProps`, `WorkflowRegistry`, `WORKFLOWS` — all existing exports of `workflows.ts`.
- Produces:
  ```ts
  export interface ActionConfigSurface {
      Surface: ComponentType<WorkflowConfigProps>;
      rows: WorkflowConfigRow[];
  }

  export function configSurfaceFor(
      workflow: string | null | undefined,
      rows: WorkflowConfigRow[] | null,
      registry?: WorkflowRegistry,
  ): ActionConfigSurface | null;
  ```
  Task 2 calls `configSurfaceFor(...) !== null` to decide a row's shape.

- [ ] **Step 1: Write the failing tests**

In `resources/js/patyourself/workflows.test.ts`, add `configures` to the existing `FAKE` registry (leave the other two entries exactly as they are — `workflowFor`'s tests depend on them):

```ts
const FAKE: WorkflowRegistry = {
    'spec-fake': {
        name: 'spec-fake',
        label: 'Spec fake',
        config: null,
        record: Surface,
    },
    bare: { name: 'bare', label: 'Bare', config: null, record: null },
    configures: {
        name: 'configures',
        label: 'Configures',
        config: Surface,
        record: null,
    },
};
```

Import the new symbols at the top of the file:

```ts
import type { WorkflowConfigRow, WorkflowRegistry } from './workflows';
import { WORKFLOWS, configSurfaceFor, workflowFor } from './workflows';
```

Then append this block after the existing `describe('workflowFor', …)`:

```ts
describe('configSurfaceFor', () => {
    const ROWS: WorkflowConfigRow[] = [
        {
            id: 1,
            exercise_id: 2,
            exercise_name: 'Barbell Row',
            position: 1,
            target_sets: 3,
            target_reps: 10,
        },
    ];

    it('resolves a registered config surface and the rows it draws', () => {
        const surface = configSurfaceFor('configures', ROWS, FAKE);

        expect(surface?.Surface).toBe(Surface);
        expect(surface?.rows).toBe(ROWS);
    });

    it('resolves nothing when the loop names no workflow', () => {
        expect(configSurfaceFor(null, ROWS, FAKE)).toBeNull();
        expect(configSurfaceFor(undefined, ROWS, FAKE)).toBeNull();
    });

    it('resolves nothing for a name the registry does not know', () => {
        expect(configSurfaceFor('gimnasio', ROWS, FAKE)).toBeNull();
    });

    // The same prototype-chain trap workflowFor guards, reached through the
    // resolver instead. A bare lookup would return a truthy inherited value
    // and the caller would read .config off it.
    it('resolves nothing for an inherited property name', () => {
        expect(configSurfaceFor('constructor', ROWS, FAKE)).toBeNull();
        expect(configSurfaceFor('toString', ROWS, FAKE)).toBeNull();
    });

    it('resolves nothing for a workflow whose config site is empty', () => {
        expect(configSurfaceFor('spec-fake', ROWS, FAKE)).toBeNull();
    });

    it('resolves nothing when the loop does not configure actions', () => {
        expect(configSurfaceFor('configures', null, FAKE)).toBeNull();
    });

    // rows: [] is "this action's routine is empty", which still has a
    // surface to draw. Only rows: null means there is no surface at all.
    it('resolves a surface for an empty routine', () => {
        const surface = configSurfaceFor('configures', [], FAKE);

        expect(surface).not.toBeNull();
        expect(surface?.rows).toEqual([]);
    });

    it('resolves gym against the shipped registry by default', () => {
        expect(configSurfaceFor('gym', [])).not.toBeNull();
        expect(configSurfaceFor('gym', null)).toBeNull();
        expect(configSurfaceFor(null, [])).toBeNull();
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/workflows.test.ts`
Expected: FAIL — `configSurfaceFor is not a function` / TypeScript cannot find the export.

- [ ] **Step 3: Implement the resolver**

In `resources/js/patyourself/workflows.ts`, append after `workflowFor`:

```ts
/**
 * The configuration surface this loop draws for one action, with the rows it
 * draws, or null when it draws none.
 *
 * There are three ways to draw nothing and they are not the same thing: the
 * loop names no workflow (or one this registry does not know), the workflow it
 * names has an empty config site, or the loop does not configure actions at all
 * and the server sent `rows: null`. All three resolve here, once.
 *
 * `rows: null` and `rows: []` stay distinct — "this loop has no configuration
 * surface" against "this action's routine is empty". An empty routine still has
 * a surface, and collapsing the two would draw an editor on a loop that has
 * none.
 *
 * Returns the surface and the rows rather than a boolean because
 * `WorkflowConfig` needs both, narrowed: a boolean return cannot tell
 * TypeScript that `rows` is no longer null. `ActionLayer` wants only the
 * question answered and compares the result against null.
 *
 * The alternative was for the slot and the action layer to each hold this
 * condition. They would then be able to disagree, and the shape of that
 * disagreement is an action collapsed behind a disclosure whose body is empty,
 * with its own controls hidden inside.
 */
export function configSurfaceFor(
    workflow: string | null | undefined,
    rows: WorkflowConfigRow[] | null,
    registry: WorkflowRegistry = WORKFLOWS,
): ActionConfigSurface | null {
    const spec = workflowFor(workflow, registry);

    if (spec === null || spec.config === null || rows === null) {
        return null;
    }

    return { Surface: spec.config, rows };
}
```

And add the interface above it, next to `WorkflowSpec`:

```ts
/** A resolved configuration surface and the rows it has been given to draw. */
export interface ActionConfigSurface {
    Surface: ComponentType<WorkflowConfigProps>;
    rows: WorkflowConfigRow[];
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run resources/js/patyourself/workflows.test.ts`
Expected: PASS, all `configSurfaceFor` cases green.

- [ ] **Step 5: Route `WorkflowConfig` through the resolver**

Replace the body of `WorkflowConfig` in `resources/js/patyourself/workflow-config.tsx` (the condition it currently holds is now the resolver's):

```tsx
export function WorkflowConfig({
    workflow,
    actionId,
    rows,
    registry = WORKFLOWS,
}: WorkflowConfigSlotProps) {
    // The same resolver `ActionLayer` asks before it chooses a row shape. Held
    // in one place so the slot and its host cannot disagree about whether there
    // is a surface here — a disagreement draws a collapsed action whose body is
    // empty and whose controls are hidden inside it.
    const surface = configSurfaceFor(workflow, rows, registry);

    if (surface === null) {
        return null;
    }

    const Config = surface.Surface;

    return (
        <WorkflowConfigBoundary key={workflow}>
            <Config actionId={actionId} rows={surface.rows} />
        </WorkflowConfigBoundary>
    );
}
```

Update its import line:

```tsx
import { WORKFLOWS, configSurfaceFor } from '@/patyourself/workflows';
```

`workflowFor` is no longer used in this file — remove it from the import, or eslint will flag it.

- [ ] **Step 6: Run the config slot's existing tests unchanged**

Run: `npx vitest run resources/js/patyourself/workflow-config.test.tsx`
Expected: PASS, with **no edits to that file**. It is the proof the refactor changed no behaviour — if a case there needs changing, the refactor is wrong, not the test.

- [ ] **Step 7: Update `docs/WORKFLOWS.md` §6**

After the paragraph beginning "**`Object.hasOwn`, never a bare lookup.**", add:

```markdown
**One resolver answers "is there a configuration surface here?"** —
`configSurfaceFor(workflow, rows, registry)` returns the resolved surface and
its rows, or null. `WorkflowConfig` uses it to decide whether to draw, and
`ActionLayer` uses it to decide whether the action collapses into a disclosure.
Held in one place because two copies can disagree, and the shape of that
disagreement is an action collapsed behind a triangle with an empty body and its
own controls hidden inside.
```

- [ ] **Step 8: Verify and commit**

Run, from inside the worktree:

```bash
npx vitest run resources/js/patyourself/workflows.test.ts resources/js/patyourself/workflow-config.test.tsx
npx tsc --noEmit
npm run lint
```

Expected: all green, 0 TypeScript errors.

```bash
git add resources/js/patyourself/workflows.ts resources/js/patyourself/workflows.test.ts resources/js/patyourself/workflow-config.tsx docs/WORKFLOWS.md
git commit -m "refactor(workflows): give the config site one resolver

WorkflowConfig computed whether a configuration surface exists and threw
the answer away. The action layer needs the same answer to choose a row
shape, so the condition moves to configSurfaceFor() and both call it.
Two copies could disagree, and that disagreement draws a collapsed action
with an empty body."
```

---

### Task 2: Collapse the actions that have a routine

**Files:**
- Modify: `resources/js/patyourself/loops/action-layer.tsx`
- Test: `resources/js/patyourself/loops/action-layer.test.tsx`
- Modify: `docs/GYM.md` §5, `docs/WORKFLOWS.md` §3

**Interfaces:**
- Consumes: `configSurfaceFor(workflow, rows, registry)` from Task 1; `WorkflowRegistry` from `workflows.ts`.
- Produces: `ActionLayer` gains an optional `registry?: WorkflowRegistry` prop, defaulting to the shipped registry. `ActionSummary` is unchanged by this task — Spec B adds fields to it.

- [ ] **Step 1: Write the failing tests**

Replace the imports at the top of `resources/js/patyourself/loops/action-layer.test.tsx` with:

```tsx
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import type {
    WorkflowConfigProps,
    WorkflowRegistry,
} from '@/patyourself/workflows';
import { ActionLayer } from './action-layer';
```

Keep every existing test in the file exactly as it is — they cover the flat row and must stay green untouched. Append:

```tsx
/**
 * Stands in for a module's configuration surface. The real RoutineEditor is
 * never rendered here, for the reason workflow-config.test.tsx gives: the
 * action layer's job is to decide the row's shape, not to draw gym.
 */
function FakeRoutine({ actionId, rows }: WorkflowConfigProps) {
    return (
        <div data-testid={`fake-routine-${actionId}`}>
            {rows.length === 0 ? (
                <p>No exercises on this one yet.</p>
            ) : (
                rows.map((row) => <p key={row.id}>{row.exercise_name}</p>)
            )}
            <p>Add an exercise</p>
        </div>
    );
}

const CONFIGURING: WorkflowRegistry = {
    fake: { name: 'fake', label: 'Fake', config: FakeRoutine, record: null },
};

const pullDay = {
    id: 7,
    title: 'Pull day',
    cadence: 'weekly at 07:30',
    scheduleKind: 'clock' as const,
    time: '07:30',
    recurrence: 'weekly',
    anchor: null,
    routine: [
        {
            id: 11,
            exercise_id: 2,
            exercise_name: 'Barbell Row',
            position: 1,
            target_sets: 3,
            target_reps: 10,
        },
    ],
};

const pushDay = {
    ...pullDay,
    id: 8,
    title: 'Push day',
    routine: [
        {
            id: 12,
            exercise_id: 3,
            exercise_name: 'Overhead Press',
            position: 1,
            target_sets: 3,
            target_reps: 8,
        },
    ],
};

describe('ActionLayer disclosure', () => {
    it('collapses an action that has a configuration surface', () => {
        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        expect(screen.getByText('Pull day')).toBeVisible();
        expect(screen.getByText('weekly at 07:30')).toBeVisible();
        expect(screen.getByText('Barbell Row')).not.toBeVisible();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).not.toBeVisible();
        expect(
            screen.getByRole('button', { name: /retire/i }),
        ).not.toBeVisible();
    });

    it('reveals the routine and the action’s own controls when opened', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        await user.click(screen.getByText('Pull day'));

        expect(screen.getByText('Barbell Row')).toBeVisible();
        expect(screen.getByText('Add an exercise')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: /retire/i })).toBeVisible();
    });

    // Plain <details>, no `name` grouping and no shared state: comparing two
    // sessions' routines is an ordinary thing to want.
    it('leaves one action open when another is opened', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay, pushDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        await user.click(screen.getByText('Pull day'));
        await user.click(screen.getByText('Push day'));

        expect(screen.getByText('Barbell Row')).toBeVisible();
        expect(screen.getByText('Overhead Press')).toBeVisible();
    });

    // An empty routine is still a routine — rows: [] is not rows: null.
    it('collapses an action whose routine is empty', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[{ ...pullDay, routine: [] }]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        expect(
            screen.getByText('No exercises on this one yet.'),
        ).not.toBeVisible();

        await user.click(screen.getByText('Pull day'));

        expect(
            screen.getByText('No exercises on this one yet.'),
        ).toBeVisible();
    });

    /**
     * The client-side sibling of PlainLoopIsUnchangedTest, and the regression
     * that matters most here: a loop with no workflow must not grow a
     * disclosure with nothing behind it.
     */
    it('leaves a plain loop’s action flat, with its controls reachable', () => {
        const { container } = render(
            <ActionLayer
                loopId={2}
                actions={[{ ...pullDay, routine: null }]}
                registry={CONFIGURING}
            />,
        );

        expect(container.querySelector('li > details')).toBeNull();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).toBeVisible();
        expect(screen.getByRole('button', { name: /retire/i })).toBeVisible();
    });

    /**
     * A workflow registered on the server and absent from the client sends
     * rows while the client registry resolves nothing. docs/WORKFLOWS.md §11
     * records that state as "configurable and draws nothing"; it must not
     * become "configurable, draws nothing, and hides the controls".
     */
    it('leaves an action flat when the client registry does not know the loop’s workflow', () => {
        const { container } = render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="journal"
                registry={CONFIGURING}
            />,
        );

        expect(container.querySelector('li > details')).toBeNull();
        expect(
            screen.getByRole('button', { name: /edit pull day/i }),
        ).toBeVisible();
    });

    it('opens the editor inside the body and leaves the disclosure open', async () => {
        const user = userEvent.setup();

        render(
            <ActionLayer
                loopId={2}
                actions={[pullDay]}
                workflow="fake"
                registry={CONFIGURING}
            />,
        );

        await user.click(screen.getByText('Pull day'));
        await user.click(screen.getByRole('button', { name: /edit pull day/i }));

        expect(screen.getByTestId('action-editor-7')).toBeVisible();
        expect(screen.getByText('Barbell Row')).toBeVisible();
    });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run resources/js/patyourself/loops/action-layer.test.tsx`
Expected: FAIL — `registry` is not a prop of `ActionLayer`, and the collapse assertions fail because everything renders flat and visible.

- [ ] **Step 3: Extract the two pieces the row shapes share**

In `resources/js/patyourself/loops/action-layer.tsx`, add these two components below `ActionLayer` (above `ActionEditor`):

```tsx
/**
 * What an action says about itself: its title, and its cadence when it has one
 * to name.
 *
 * Rendered inside `<summary>` for an action that collapses and inside the flat
 * row for one that does not, so the two shapes cannot drift into describing an
 * action differently.
 */
function ActionHeader({ action }: { action: ActionSummary }) {
    return (
        <span>
            <span className="block">{action.title}</span>
            {action.cadence !== null && (
                <span className="block text-sm opacity-70">
                    {action.cadence}
                </span>
            )}
        </span>
    );
}

/**
 * The two things that can be done to an action from the layer.
 *
 * These live *outside* `<summary>` for a collapsing action, which is not a
 * layout preference: `Retire` is a submit button inside a form, and a button
 * inside `<summary>` both submits and toggles the disclosure on one click.
 */
function ActionControls({
    action,
    onEdit,
}: {
    action: ActionSummary;
    onEdit: () => void;
}) {
    return (
        <span className="flex items-center gap-1">
            <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={`Edit ${action.title}`}
                onClick={onEdit}
            >
                Edit
            </Button>
            <Form {...destroy.form(action.id)}>
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                    >
                        Retire
                    </Button>
                )}
            </Form>
        </span>
    );
}
```

- [ ] **Step 4: Give `ActionLayer` the registry prop and the two row shapes**

Update the imports:

```tsx
import type { WorkflowConfigRow, WorkflowRegistry } from '@/patyourself/workflows';
import { WORKFLOWS, configSurfaceFor } from '@/patyourself/workflows';
```

Extend `Props`:

```tsx
type Props = {
    loopId: number;
    actions: ActionSummary[];
    /** The loop's workflow, or null for a plain loop, which draws nothing
     *  extra here. */
    workflow?: string | null;
    /** Injectable so a test can exercise the collapsed shape without a second
     *  module having been shipped — the same reason `WorkflowConfig` takes
     *  one. Defaults to the registry the app actually draws. */
    registry?: WorkflowRegistry;
};
```

Replace the `<ul>` block in `ActionLayer` with:

```tsx
export function ActionLayer({
    loopId,
    actions,
    workflow = null,
    registry = WORKFLOWS,
}: Props) {
    const [kind, setKind] = useState<'clock' | 'anchored'>('clock');
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <div className="space-y-4">
            <ul className="space-y-2">
                {actions.map((action) => {
                    const rows = action.routine ?? null;
                    const isEditing = editing === action.id;

                    // The same resolver the slot itself uses. An action only
                    // collapses when there is genuinely something behind the
                    // triangle: a plain loop, an unknown workflow name, or a
                    // module with no config site all keep the flat row they
                    // have always had.
                    const collapses =
                        configSurfaceFor(workflow, rows, registry) !== null;

                    const controls = isEditing ? (
                        <ActionEditor
                            action={action}
                            onDone={() => setEditing(null)}
                        />
                    ) : (
                        <ActionControls
                            action={action}
                            onEdit={() => setEditing(action.id)}
                        />
                    );

                    // Draws nothing for a plain loop, which is every loop
                    // with no workflow — the row then reads exactly as it
                    // always has.
                    const config = (
                        <WorkflowConfig
                            workflow={workflow}
                            actionId={action.id}
                            rows={rows}
                            registry={registry}
                        />
                    );

                    if (collapses) {
                        return (
                            <li key={action.id} className="space-y-2">
                                <details>
                                    <summary className="cursor-pointer">
                                        <ActionHeader action={action} />
                                    </summary>
                                    <div className="space-y-2 pt-2">
                                        {controls}
                                        {config}
                                    </div>
                                </details>
                            </li>
                        );
                    }

                    return (
                        <li key={action.id} className="space-y-2">
                            {isEditing ? (
                                controls
                            ) : (
                                <div className="flex items-center justify-between gap-3">
                                    <ActionHeader action={action} />
                                    {controls}
                                </div>
                            )}
                            {config}
                        </li>
                    );
                })}
            </ul>
```

Two notes on that shape:

- An early `return` inside the `map` callback rather than a ternary choosing between three outcomes. A nested ternary in JSX is the obvious alternative and is worse to read and worse to lint.
- The flat branch keeps the header and the controls side by side on one row — which is exactly what the screen looks like today — while the collapsed branch stacks them under the summary. That difference is why the flat branch does not reuse the collapsed branch's layout.

The rest of the component — the retire note and the add-an-action `<details>` — is unchanged.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `npx vitest run resources/js/patyourself/loops/action-layer.test.tsx`
Expected: PASS — the new disclosure cases and every pre-existing case in the file, including `renders no dangling cadence when there is nothing to name`, which asserts the header's structure and must not have been changed.

- [ ] **Step 6: Update the docs**

In `docs/GYM.md` §5, the paragraph beginning "**The routine editor** (`routine-editor.tsx`)" — after the sentence "An action's configuration belongs beside the action, not on a screen of its own.", add:

```markdown
The action collapses to its title and cadence and the editor is disclosed
behind it, so a loop with three sessions reads as three lines. The disclosure
belongs to the action layer rather than to this file, so the second module
inherits it; `Edit` and `Retire` sit inside the disclosed body, because a submit
button inside `<summary>` would submit and toggle on the same click.
```

In `docs/WORKFLOWS.md` §3, in the table row for **`config`**, leave the columns as they are and add this sentence under the table, after the paragraph ending "…a record belongs to the occasion because it is a fact about that one time.":

```markdown
The config site is *disclosed*, and the record site is not. A configuration is
reference material read far less often than it is scrolled past, so the action
layer collapses an action that has one; a record surface is one line that gets a
person into the occasion, and collapsing it would put a click in front of the
thing the screen exists for.
```

- [ ] **Step 7: Full verification**

Run, from inside the worktree, in this order:

```bash
npm run build
php artisan test --compact
npx vitest run
npx tsc --noEmit
vendor/bin/pint --dirty --format agent
npm run lint
```

Expected: **1028 PHP tests / 6533 assertions** (unchanged — this branch touches no PHP), JS tests above 496, 0 TypeScript errors, pint reporting nothing to fix.

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/loops/action-layer.tsx resources/js/patyourself/loops/action-layer.test.tsx docs/GYM.md docs/WORKFLOWS.md
git commit -m "feat(loops): collapse an action that configures a routine

A training loop rendered every action's whole routine inline, so three
gym sessions were fifteen exercise rows in one flat list. An action that
has a configuration surface now collapses to its title and cadence, with
the routine and the action's own controls disclosed behind it.

Plain <details>, many open at once. Edit and Retire move into the body
because a submit button inside <summary> submits and toggles at once. A
plain loop's action keeps the flat row it has always had."
```

---

## Manual check

Neither task needs one to be correct, but the disclosure is a visual change and the worktree is not served by Herd. To look at it:

```bash
php -S 127.0.0.1:8899 -t public
```

Then open a training loop's screen. Expected: each gym action is one line with a triangle; opening one reveals `Edit`, `Retire`, the exercise rows and "Add an exercise"; opening a second leaves the first open; a non-training loop's actions look exactly as they did.
