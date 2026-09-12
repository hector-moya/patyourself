# Loop screen information architecture — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reorder the loop detail screen by how often each block is the reason you opened it, condition the verdict on `is_under_review`, and give the experiment one card instead of two disconnected halves.

**Architecture:** Frontend only. `pages/loops/show.tsx` becomes composition over five components: a new `ExperimentCard` that wraps the existing `ExperimentHeader`, a new `Anatomy` extracted from `show.tsx` and collapsed behind a one-line chain, the existing `ActionLayer` promoted to third, an amended `StrategyTimeline` that no longer renders the active version, and a new `LoopSettings` disclosure collecting the three rare controls. No server change — every prop the new components need is already sent.

**Tech Stack:** React 19, TypeScript, Inertia v3, Tailwind v4, vitest + @testing-library/react.

**Spec:** `docs/superpowers/specs/2026-09-12-loop-screen-ia-design.md`

## Global Constraints

- **Copy:** sentence case, no exclamation marks, never congratulating, no second person keeping score.
- **`pages/loops/show.tsx` and `patyourself/loops/action-layer.tsx` ARE on `CompanionVocabularyTest::sourceFiles()`.** Any new file under `patyourself/loops/` that this plan creates must be added to that list, and must not contain: `streak`, `congratulation`, `well done`, `completion rate`, `percent`, `points`, `level up`, `lonely`, `hungry`, `misses you`, `neglect`, `cooldown` — **comments included**. `points` and `percent` are substring traps: *endpoints* and *percentage* both trip them.
- **`<details>` keeps its content in the DOM when collapsed.** Never assert a moved control is "absent" with `queryBy…`; assert on its ancestry instead. See Task 2 Step 1.
- **No logging controls on this screen.** Recording stays on Today, catch-up and the session screen.
- **No server, resource, controller or migration change.**
- Run `npm run build` before `php artisan test` or `PwaManifestTest` skips itself and ~470 assertions vanish.
- Baseline to hold: 1015 PHP tests / 6380 assertions, 467 JS tests, 0 TypeScript errors. The JS count rises.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `resources/js/patyourself/loops/anatomy.tsx` | **new** — the four-stage anatomy, plus the collapsed one-line chain that discloses it |
| `resources/js/patyourself/loops/anatomy.test.tsx` | **new** |
| `resources/js/patyourself/loops/experiment-card.tsx` | **new** — borders `ExperimentHeader`, adds the hypothesis and the verdict slot |
| `resources/js/patyourself/loops/experiment-card.test.tsx` | **new** |
| `resources/js/patyourself/loops/loop-settings.tsx` | **new** — one disclosure over Recording, Start the next experiment, End this experiment early |
| `resources/js/patyourself/strategy-timeline.tsx` | modify — exclude the active unconcluded version, rename, render nothing when empty |
| `resources/js/pages/loops/show.tsx` | modify — composition and the new order; loses its local `Anatomy` and `WorkflowPicker` |
| `resources/js/pages/loops/show.test.tsx` | modify — three rewrites, several additions |
| `tests/Feature/Companion/CompanionVocabularyTest.php` | modify — register the three new files |
| `resources/js/patyourself/experiment-header.tsx` | **untouched** |
| `resources/js/patyourself/loops/conclude-experiment-form.tsx` | **untouched** |
| `resources/js/patyourself/loops/action-layer.tsx` | **untouched** |

---

### Task 1: Anatomy behind a one-line chain

Extract the 90-line `Anatomy` out of `show.tsx` and collapse it behind a summary line that still says where the strategy acts.

**Files:**
- Create: `resources/js/patyourself/loops/anatomy.tsx`
- Create: `resources/js/patyourself/loops/anatomy.test.tsx`
- Modify: `resources/js/pages/loops/show.tsx` (remove the local `Anatomy` and `STAGES`, import the new one; leave its position alone for now)
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

**Interfaces:**
- Consumes: `IntentionData` from `@/patyourself/types`, `cn` from `@/lib/utils`.
- Produces: `export function Anatomy({ intention, interventionPoint }: { intention: IntentionData; interventionPoint: string | null })`. Renders a `<details data-testid="habit-anatomy">` whose summary carries `<span data-testid="loop-chain">`. The acting stage's word carries `data-acting="true"`.

- [ ] **Step 1: Write the failing test**

Create `resources/js/patyourself/loops/anatomy.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { IntentionData } from '@/patyourself/types';

import { Anatomy } from './anatomy';

function intention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 1,
        title: 'Read before bed',
        type: 'build',
        status: 'active',
        workflow: null,
        cue: 'Phone on the charger',
        craving: 'Wind down',
        response: 'Read ten pages',
        reward: 'Calmer sleep',
        description: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: null,
        active_action: null,
        ...overrides,
    };
}

describe('Anatomy', () => {
    /**
     * The chain is the loop's identity, so it must survive the collapse. The
     * four stage words are the summary; the stage content is not.
     *
     * Killing mutation: render the summary as the literal text "Habit anatomy".
     * The four assertions below fail — the identity would be gone from the
     * collapsed state, which is the whole reason the line exists.
     */
    it('names the four stages in the collapsed line', () => {
        render(<Anatomy intention={intention()} interventionPoint="cue" />);

        const chain = screen.getByTestId('loop-chain');

        expect(chain).toHaveTextContent(/cue/i);
        expect(chain).toHaveTextContent(/craving/i);
        expect(chain).toHaveTextContent(/response/i);
        expect(chain).toHaveTextContent(/reward/i);
    });

    /**
     * Where the strategy acts is the one fact the anatomy carries that
     * actually changes, so it is the one fact that must not need a tap.
     *
     * Killing mutation: drop the `data-acting` attribute, or set it on every
     * stage. Either way exactly-one-marked fails.
     */
    it('marks the acting stage in the collapsed line, and only that one', () => {
        const { container } = render(
            <Anatomy intention={intention()} interventionPoint="response" />,
        );

        const marked = container.querySelectorAll('[data-acting="true"]');

        expect(marked).toHaveLength(1);
        expect(marked[0]).toHaveTextContent(/response/i);
    });

    /**
     * A loop between experiments has no intervention point. Marking a stage
     * anyway would claim a strategy acts somewhere it does not.
     */
    it('marks nothing when there is no intervention point', () => {
        const { container } = render(
            <Anatomy intention={intention()} interventionPoint={null} />,
        );

        expect(container.querySelectorAll('[data-acting="true"]')).toHaveLength(
            0,
        );
    });

    /**
     * Collapsed is the default: the anatomy costs about a screen and a half
     * and changes perhaps twice in a loop's life.
     *
     * Note `<details>` keeps its content in the DOM either way, so this
     * asserts the element's own open state rather than the content's absence.
     */
    it('is collapsed by default', () => {
        render(<Anatomy intention={intention()} interventionPoint="cue" />);

        expect(screen.getByTestId('habit-anatomy')).not.toHaveAttribute('open');
    });

    it('carries every stage’s wording once disclosed', () => {
        render(<Anatomy intention={intention()} interventionPoint="cue" />);

        expect(
            screen.getByText(/phone on the charger/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/wind down/i)).toBeInTheDocument();
        expect(screen.getByText(/read ten pages/i)).toBeInTheDocument();
        expect(screen.getByText(/calmer sleep/i)).toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/patyourself/loops/anatomy.test.tsx`
Expected: FAIL — `Failed to resolve import "./anatomy"`.

- [ ] **Step 3: Create the component**

Create `resources/js/patyourself/loops/anatomy.tsx`:

```tsx
import { cn } from '@/lib/utils';
import type { IntentionData } from '@/patyourself/types';

/**
 * Each stage carries its own accent, defined as `--stage-*` in patyourself.css.
 * The four exist in the palette and are named for exactly these stages; painting
 * the intervention point with the generic primary threw that away and made the
 * chain read as one undifferentiated list.
 */
const STAGES = [
    { key: 'cue', label: 'Cue', hint: 'the trigger', accent: 'cue' },
    {
        key: 'craving',
        label: 'Craving',
        hint: 'the motivation',
        accent: 'craving',
    },
    {
        key: 'response',
        label: 'Response',
        hint: 'the behaviour',
        accent: 'response',
    },
    { key: 'reward', label: 'Reward', hint: 'the payoff', accent: 'reward' },
] as const;

/**
 * The loop's four stages, collapsed behind the chain that names them.
 *
 * The anatomy is the loop's identity and it is also the least changeable thing
 * on the screen — it is written once at authoring and then read occasionally.
 * Full-size it cost about a screen and a half above the actions, so it is
 * disclosed rather than drawn.
 *
 * What survives the collapse is chosen, not incidental: the four stage words,
 * so the loop still looks like itself, and a mark on the stage the active
 * strategy acts upon, because that is the only part of the anatomy that moves
 * over a loop's life. Everything else waits behind the tap.
 *
 * A `<details>` rather than a modal or a tab: this screen already discloses two
 * other controls the same way, and a disclosure needs no focus management and
 * no second navigation layer above the bottom nav.
 */
export function Anatomy({
    intention,
    interventionPoint,
}: {
    intention: IntentionData;
    interventionPoint: string | null;
}) {
    return (
        <details data-testid="habit-anatomy">
            <summary className="ds-label cursor-pointer">
                <LoopChain interventionPoint={interventionPoint} />
            </summary>

            <ol className="relative mt-3 flex flex-col gap-2">
                {STAGES.map((stage, index) => {
                    const acts = stage.key === interventionPoint;

                    return (
                        <li key={stage.key} className="flex gap-3">
                            <div className="flex flex-col items-center">
                                <span
                                    className="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
                                    style={{
                                        borderColor: `var(--stage-${stage.accent})`,
                                        backgroundColor: acts
                                            ? `var(--stage-${stage.accent})`
                                            : `var(--stage-${stage.accent}-soft)`,
                                        color: acts
                                            ? 'var(--stage-on-accent, #FFF8F3)'
                                            : `var(--stage-${stage.accent})`,
                                    }}
                                >
                                    {index + 1}
                                </span>
                                {index < STAGES.length - 1 && (
                                    <span className="my-1 w-px flex-1 bg-border" />
                                )}
                            </div>

                            <div
                                className={cn(
                                    'mb-1 flex-1 rounded-xl border p-3',
                                    !acts && 'border-border',
                                )}
                                style={
                                    acts
                                        ? {
                                              borderColor: `var(--stage-${stage.accent})`,
                                              backgroundColor: `var(--stage-${stage.accent}-soft)`,
                                          }
                                        : undefined
                                }
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span
                                        className="text-xs font-semibold tracking-wide uppercase"
                                        style={{
                                            color: `var(--stage-${stage.accent})`,
                                        }}
                                    >
                                        {stage.label}
                                        <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                                            · {stage.hint}
                                        </span>
                                    </span>
                                    {acts && (
                                        <span
                                            className="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            style={{
                                                backgroundColor: `var(--stage-${stage.accent}-soft)`,
                                                color: `var(--stage-${stage.accent})`,
                                            }}
                                        >
                                            strategy acts here
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 text-sm text-foreground">
                                    {intention[stage.key]}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </details>
    );
}

/**
 * The chain, on one line, in the summary.
 *
 * The acting stage is drawn at full weight in its own accent and the other
 * three are muted. No badge and no extra wording — the value of this line is
 * that it is one line, and anything that can wrap defeats it.
 */
function LoopChain({ interventionPoint }: { interventionPoint: string | null }) {
    return (
        <span
            data-testid="loop-chain"
            className="inline-flex flex-wrap items-center gap-1 normal-case"
        >
            {STAGES.map((stage, index) => {
                const acts = stage.key === interventionPoint;

                return (
                    <span key={stage.key} className="inline-flex items-center gap-1">
                        {index > 0 && (
                            <span aria-hidden="true" className="text-muted-foreground/50">
                                →
                            </span>
                        )}
                        <span
                            data-acting={acts ? 'true' : undefined}
                            className={cn(
                                acts ? 'font-semibold' : 'text-muted-foreground',
                            )}
                            style={
                                acts
                                    ? { color: `var(--stage-${stage.accent})` }
                                    : undefined
                            }
                        >
                            {stage.label}
                        </span>
                    </span>
                );
            })}
        </span>
    );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `npx vitest run resources/js/patyourself/loops/anatomy.test.tsx`
Expected: PASS, 5 tests.

- [ ] **Step 5: Point `show.tsx` at it**

In `resources/js/pages/loops/show.tsx`:
1. Delete the local `function Anatomy(...)` (currently lines 397–481) and the local `const STAGES = [...]` (currently lines 380–395).
2. Delete the now-unused `cn` import if nothing else in the file uses it — check with `grep -n 'cn(' resources/js/pages/loops/show.tsx` first.
3. Add the import beside the other `loops/` imports:

```tsx
import { Anatomy } from '@/patyourself/loops/anatomy';
```

Leave the `<Anatomy … />` call exactly where it is. Reordering happens in Task 5.

- [ ] **Step 6: Register the new file for the vocabulary scan**

In `tests/Feature/Companion/CompanionVocabularyTest.php`, in `sourceFiles()`, beside the other `resources/js/patyourself/loops/` entry:

```php
$root.'/resources/js/patyourself/loops/anatomy.tsx',
```

- [ ] **Step 7: Run the affected suites**

```bash
npx vitest run resources/js/patyourself/loops/anatomy.test.tsx resources/js/pages/loops/show.test.tsx
php artisan test --compact --filter=CompanionVocabularyTest
npx tsc --noEmit
```

Expected: all pass. `show.test.tsx` should be unaffected — it never asserted the anatomy.

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/loops/anatomy.tsx resources/js/patyourself/loops/anatomy.test.tsx resources/js/pages/loops/show.tsx tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "refactor(loops): collapse the habit anatomy behind its chain"
```

---

### Task 2: The experiment card, with a conditional verdict

Give the experiment one card carrying its hypothesis, and render the verdict inside it only when the version is under review.

**Files:**
- Create: `resources/js/patyourself/loops/experiment-card.tsx`
- Create: `resources/js/patyourself/loops/experiment-card.test.tsx`
- Modify: `resources/js/pages/loops/show.tsx`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

**Interfaces:**
- Consumes: `ExperimentHeader` from `@/patyourself/experiment-header`; `CurrentVersionData`, `StrategyData` from `@/patyourself/types`; `ConcludeExperimentForm` from `@/patyourself/loops/conclude-experiment-form`.
- Produces:

```ts
export function ExperimentCard(props: {
    current: CurrentVersionData | null;
    activeExperiment: StrategyData | undefined;
    interventionPoint: string | null;
    previousRate: number | null;
}): JSX.Element
```

Renders `<section data-testid="experiment-card">`. Mounts `ConcludeExperimentForm` inside itself **only** when `activeExperiment?.is_under_review` is true.

- [ ] **Step 1: Write the failing test**

Note the ancestry assertions. `<details>` keeps content in the DOM, and in Task 4 the verdict gains a second home inside a disclosure — so "is it on the page" cannot distinguish the two states. "Which container is it in" can.

Create `resources/js/patyourself/loops/experiment-card.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CurrentVersionData, StrategyData } from '@/patyourself/types';

import { ExperimentCard } from './experiment-card';

function currentVersion(
    overrides: Partial<CurrentVersionData> = {},
): CurrentVersionData {
    return {
        version: 2,
        started_at: '2026-08-18T09:00:00+00:00',
        day_of_experiment: 9,
        planned_days: 14,
        is_under_review: false,
        verdict: null,
        streak: { outcome: null, length: 0 },
        completion_rate: 68,
        totals: { completed: 15, failed: 7, skipped: 0 },
        last_logged_at: null,
        ...overrides,
    };
}

function strategy(overrides: Partial<StrategyData> = {}): StrategyData {
    return {
        id: 7,
        version: 2,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        change_reason: null,
        superseded_reason: null,
        review_at: null,
        verdict: null,
        verdict_note: null,
        day_of_experiment: 9,
        planned_days: 14,
        is_under_review: false,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('ExperimentCard', () => {
    /**
     * The hypothesis is what the card exists to raise. It used to render only
     * inside the timeline, below the anatomy, which is why the experiment read
     * as buried while its version number sat at the top.
     *
     * Killing mutation: drop the `{activeExperiment.approach}` paragraph. The
     * card would still show v2 and the day count and look plausible, and this
     * assertion is the only thing that catches it.
     */
    it('carries the active version’s hypothesis', () => {
        render(
            <ExperimentCard
                current={currentVersion()}
                activeExperiment={strategy()}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        expect(
            screen.getByText(/lay your shoes by the door/i),
        ).toBeInTheDocument();
    });

    /**
     * The verdict question is only live at the end of an experiment's run.
     * `is_under_review` already says when that is — ConcludeExperimentForm has
     * always received it and used it only to reword its legend.
     *
     * Asserted by ancestry, not by presence: in Task 4 the verdict gains a
     * second mount point inside a `<details>`, and a collapsed `<details>`
     * keeps its content in the DOM. `queryByLabelText` would find it in both
     * states and this test would pass for the wrong reason.
     */
    it('does not hold the verdict while the experiment is still running', () => {
        render(
            <ExperimentCard
                current={currentVersion({ is_under_review: false })}
                activeExperiment={strategy({ is_under_review: false })}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
    });

    /**
     * Killing mutation: mount the form unconditionally. The test above fails.
     * Mount it never, and this one fails.
     */
    it('holds the verdict once the experiment is under review', () => {
        render(
            <ExperimentCard
                current={currentVersion({ is_under_review: true })}
                activeExperiment={strategy({ id: 7, is_under_review: true })}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        const option = screen.getByLabelText(/it worked/i);

        expect(option.closest('[data-testid="experiment-card"]')).not.toBeNull();
        expect(
            option.closest('form')?.getAttribute('action'),
        ).toContain('/strategies/7/verdict');
    });

    /**
     * A version already concluded has answered the question, even though a
     * `worked` verdict leaves it active. Asking again would reopen a closed
     * finding.
     */
    it('does not hold the verdict for a version already concluded', () => {
        render(
            <ExperimentCard
                current={currentVersion({ is_under_review: true })}
                activeExperiment={undefined}
                interventionPoint="cue"
                previousRate={null}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
    });

    /**
     * Between experiments the card still renders — logging continues, and the
     * screen must say so rather than showing an empty frame. ExperimentHeader
     * already words this; the card must not swallow it.
     */
    it('says logging continues when no experiment is running', () => {
        render(
            <ExperimentCard
                current={null}
                activeExperiment={undefined}
                interventionPoint={null}
                previousRate={null}
            />,
        );

        expect(screen.getByText(/logging continues/i)).toBeInTheDocument();
        expect(
            screen.queryByText(/lay your shoes by the door/i),
        ).not.toBeInTheDocument();
    });
});
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/patyourself/loops/experiment-card.test.tsx`
Expected: FAIL — `Failed to resolve import "./experiment-card"`.

- [ ] **Step 3: Create the component**

Create `resources/js/patyourself/loops/experiment-card.tsx`:

```tsx
import { ExperimentHeader } from '@/patyourself/experiment-header';
import { ConcludeExperimentForm } from '@/patyourself/loops/conclude-experiment-form';
import type { CurrentVersionData, StrategyData } from '@/patyourself/types';

interface ExperimentCardProps {
    /** The active experiment's own record. Null between experiments. */
    current: CurrentVersionData | null;
    /**
     * The active version that has not yet been concluded — the one the record
     * can still answer a review for. Undefined when there is none, which
     * includes a version concluded as `worked` and therefore still active.
     */
    activeExperiment: StrategyData | undefined;
    interventionPoint: string | null;
    previousRate: number | null;
}

/**
 * What is being tested, on one card, at the top of the loop screen.
 *
 * This exists because the experiment used to arrive in two disconnected
 * halves: `ExperimentHeader` gave the version, the intervention point and the
 * run state at the top of the screen, while the hypothesis — the part that
 * says what is actually being tried — rendered a screen and a half below,
 * inside the timeline, among superseded versions. Neither half read as the
 * subject of the page.
 *
 * It **wraps** `ExperimentHeader` rather than absorbing it. The header holds
 * `runState()` and the evidence line, it is covered by its own tests, and
 * copying either into here would give the app two places that word a run
 * state and two that decide when to show a delta.
 *
 * The verdict lives here only while the question is live. See below.
 */
export function ExperimentCard({
    current,
    activeExperiment,
    interventionPoint,
    previousRate,
}: ExperimentCardProps) {
    // The verdict is the end of an experiment's life, not its daily business.
    // `is_under_review` is the app's existing answer to "is that question live
    // yet" — Strategy::isUnderReview() on the server, and the same flag
    // ExperimentHeader already reads to word the run state "Ready for a
    // verdict". When it is not live the form is not gone, it is in loop
    // settings as "End this experiment early"; rare is not forbidden.
    const readyForVerdict = activeExperiment?.is_under_review === true;

    return (
        <section
            data-testid="experiment-card"
            className="flex flex-col gap-3 rounded-xl border border-border p-4"
        >
            <ExperimentHeader
                current={current}
                interventionPoint={interventionPoint}
                previousRate={previousRate}
            />

            {activeExperiment && (
                <p className="text-sm text-foreground">
                    {activeExperiment.approach}
                </p>
            )}

            {readyForVerdict && activeExperiment && (
                <ConcludeExperimentForm
                    strategyId={activeExperiment.id}
                    isUnderReview
                />
            )}
        </section>
    );
}
```

- [ ] **Step 4: Run the test and watch it pass**

Run: `npx vitest run resources/js/patyourself/loops/experiment-card.test.tsx`
Expected: PASS, 5 tests.

- [ ] **Step 5: Swap it into `show.tsx`**

Replace the `<ExperimentHeader … />` block and the `{activeExperiment && <ConcludeExperimentForm … />}` block that follows it (currently lines 205–221) with:

```tsx
<ExperimentCard
    current={currentVersion}
    activeExperiment={activeExperiment}
    interventionPoint={intention.strategy?.intervention_point ?? null}
    previousRate={previousVersionRate(experiments, currentVersion)}
/>
```

Update the imports: drop `ExperimentHeader` and `ConcludeExperimentForm`, add

```tsx
import { ExperimentCard } from '@/patyourself/loops/experiment-card';
```

`ConcludeExperimentForm` returns in Task 4, so expect to re-add its import then.

- [ ] **Step 6: Fix the two `show.test.tsx` cases this changes**

`offers a verdict for the active, unconcluded version` (currently line 355) asserted the verdict renders for an active version whose `is_under_review` defaults to `false`. That is the behaviour being deliberately changed. Replace that single test with these two:

```tsx
    /**
     * The verdict is the end of an experiment's life. A running version does
     * not put the question on the page — it waits in loop settings, which
     * Task 4 adds.
     */
    it('does not put the verdict in the experiment card while the version is running', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({
                        id: 7,
                        status: 'active',
                        verdict: null,
                        is_under_review: false,
                    }),
                ]}
                {...record}
            />,
        );

        expect(
            screen
                .queryByLabelText(/it worked/i)
                ?.closest('[data-testid="experiment-card"]') ?? null,
        ).toBeNull();
    });

    it('puts the verdict in the experiment card once the version is under review', () => {
        const { container } = render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({
                        id: 7,
                        status: 'active',
                        verdict: null,
                        is_under_review: true,
                    }),
                ]}
                {...record}
            />,
        );

        expect(
            screen
                .getByLabelText(/it worked/i)
                .closest('[data-testid="experiment-card"]'),
        ).not.toBeNull();
        expect(
            container
                .querySelector('form[action*="/verdict"]')
                ?.getAttribute('action'),
        ).toContain('/strategies/7/verdict');
    });
```

`leads with the experiment and the reflection` (currently line 243) still passes — `experiment-state` and the reflection are both still rendered. Leave it until Task 5, which rewrites it for the new order.

- [ ] **Step 7: Register the new file for the vocabulary scan**

```php
$root.'/resources/js/patyourself/loops/experiment-card.tsx',
```

- [ ] **Step 8: Run the affected suites**

```bash
npx vitest run resources/js/patyourself/loops resources/js/pages/loops resources/js/patyourself/experiment-header.test.tsx
php artisan test --compact --filter=CompanionVocabularyTest
npx tsc --noEmit
```

Expected: all pass. `experiment-header.test.tsx` must pass untouched — if it does not, the card is doing more than wrapping.

- [ ] **Step 9: Commit**

```bash
git add resources/js/patyourself/loops/experiment-card.tsx resources/js/patyourself/loops/experiment-card.test.tsx resources/js/pages/loops/show.tsx resources/js/pages/loops/show.test.tsx tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(loops): give the experiment one card and condition its verdict"
```

---

### Task 3: Past experiments only

The active version is now the card at the top. Rendering it again in the timeline says the same thing twice and leaves the timeline reading as the place the experiment lives.

**Files:**
- Modify: `resources/js/patyourself/strategy-timeline.tsx`
- Modify: `resources/js/pages/loops/show.tsx`
- Test: `resources/js/pages/loops/show.test.tsx`

**Interfaces:**
- Produces: `StrategyTimeline` gains one required prop.

```ts
export function StrategyTimeline(props: {
    strategies: StrategyData[];
    experiments?: ExperimentData[];
    /** The version rendered by the experiment card, excluded here. Null when
     *  there is none. */
    activeVersion: number | null;
}): JSX.Element | null
```

Returns `null` when nothing remains after the exclusion.

- [ ] **Step 1: Write the failing test**

Add to `resources/js/pages/loops/show.test.tsx`, inside `describe('LoopShow', …)`:

```tsx
    /**
     * The active version is the card at the top. Drawing it again below is the
     * duplication that made the experiment feel both prominent and missing —
     * a version number up top, its hypothesis a screen and a half down.
     *
     * Killing mutation: pass every strategy through. "Lay your shoes by the
     * door" would appear twice and the length assertion fails.
     */
    it('does not repeat the active version under past experiments', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({
                        id: 7,
                        version: 2,
                        status: 'active',
                        verdict: null,
                        approach: 'Lay your shoes by the door',
                    }),
                    strategy({
                        id: 6,
                        version: 1,
                        status: 'superseded',
                        verdict: 'failed',
                        approach: 'Put the book on the pillow',
                    }),
                ]}
                {...record}
                current_version={currentVersion({ version: 2 })}
            />,
        );

        expect(
            screen.getAllByText(/lay your shoes by the door/i),
        ).toHaveLength(1);
        expect(
            screen.getByText(/put the book on the pillow/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/past experiments/i)).toBeInTheDocument();
    });

    /**
     * A first experiment has no past. An empty "Past experiments (0)" heading
     * is a slot inviting something that does not exist yet.
     */
    it('draws no past-experiments section on a loop’s first experiment', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({ id: 7, version: 1, status: 'active', verdict: null }),
                ]}
                {...record}
                current_version={currentVersion({ version: 1 })}
            />,
        );

        expect(
            screen.queryByText(/past experiments/i),
        ).not.toBeInTheDocument();
    });
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx -t "past experiments"`
Expected: FAIL — both. The first finds two matches for the shoes text; the second finds a "Past experiments" heading that is currently worded "Experiments".

- [ ] **Step 3: Amend `StrategyTimeline`**

In `resources/js/patyourself/strategy-timeline.tsx`, replace the component's signature and body (currently lines 44–90) with:

```tsx
/**
 * The experiment ladder: the versions that came before this one, oldest →
 * newest, with the evidence recorded under each. Read-only — history is only
 * ever appended to.
 *
 * The active version is excluded, because `ExperimentCard` at the top of the
 * loop screen already renders it in full. Drawing it in both places is what
 * made the experiment read as simultaneously prominent and buried: its version
 * number above the fold and its hypothesis a screen and a half below, among
 * superseded versions.
 *
 * Renders nothing at all when the exclusion empties the list — a loop's first
 * experiment has no past, and a heading over nothing is an empty slot
 * inviting something that does not exist yet.
 *
 * When `experiments` is supplied the per-version totals replace the plain
 * outcome count, which is what turns a list of things tried into a comparison
 * between them. Logs attribute through `actions.strategy_id`, so a v1 failure
 * stays on v1 even while v2 is the active version.
 */
export function StrategyTimeline({
    strategies,
    experiments,
    activeVersion,
}: {
    strategies: StrategyData[];
    experiments?: ExperimentData[];
    /** The version the experiment card renders. Null when there is none. */
    activeVersion: number | null;
}) {
    const past =
        activeVersion === null
            ? strategies
            : strategies.filter(
                  (strategy) => strategy.version !== activeVersion,
              );

    if (past.length === 0) {
        return null;
    }

    return (
        <section>
            <SectionHeading>
                Past experiments
                <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                    ({past.length})
                </span>
            </SectionHeading>

            <ol className="flex flex-col">
                {past.map((strategy, index) => (
                    <TimelineNode
                        key={strategy.id}
                        strategy={strategy}
                        experiment={experiments?.find(
                            (candidate) =>
                                candidate.version === strategy.version,
                        )}
                        last={index === past.length - 1}
                    />
                ))}
            </ol>
        </section>
    );
}
```

Note the `No strategy yet.` branch goes away with the empty case — an empty list now renders nothing.

- [ ] **Step 4: Pass the prop from `show.tsx`**

```tsx
<StrategyTimeline
    strategies={strategies}
    experiments={experiments}
    activeVersion={activeExperiment?.version ?? null}
/>
```

- [ ] **Step 5: Run the tests**

```bash
npx vitest run resources/js/pages/loops resources/js/patyourself
npx tsc --noEmit
```

Expected: pass. If another suite rendered `StrategyTimeline` directly it now needs `activeVersion`; find any with `grep -rn 'StrategyTimeline' resources/js --include='*.tsx' | grep -v strategy-timeline.tsx`.

- [ ] **Step 6: Commit**

```bash
git add resources/js/patyourself/strategy-timeline.tsx resources/js/pages/loops/show.tsx resources/js/pages/loops/show.test.tsx
git commit -m "feat(loops): keep the active version out of past experiments"
```

---

### Task 4: Loop settings

Collect the three rare, consequential controls that currently sit loose between the actions and the history.

**Files:**
- Create: `resources/js/patyourself/loops/loop-settings.tsx`
- Modify: `resources/js/pages/loops/show.tsx` (remove the local `WorkflowPicker` and the start-experiment `<details>`)
- Modify: `resources/js/pages/loops/show.test.tsx`
- Modify: `tests/Feature/Companion/CompanionVocabularyTest.php`

**Interfaces:**
- Consumes: `WorkflowOptionData` — **move this interface out of `show.tsx` into `loop-settings.tsx` and re-export it from there**, because `show.tsx` currently declares it and will now import it.
- Produces:

```ts
export interface WorkflowOptionData {
    name: string;
    label: string;
}

export function LoopSettings(props: {
    loopId: number;
    workflow: string | null;
    workflows: WorkflowOptionData[];
    /** The active unconcluded version, when the verdict is not already in the
     *  experiment card. Undefined otherwise. */
    endableExperiment: StrategyData | undefined;
    /** Whether a strategy exists to supersede. */
    canStartNext: boolean;
    currentCadence: string;
}): JSX.Element | null
```

Renders `<details data-testid="loop-settings">`. Returns `null` when it would hold nothing.

- [ ] **Step 1: Write the failing test**

Add to `show.test.tsx`:

```tsx
    /**
     * Ending an experiment before its review date is legitimate and rare. It
     * keeps a home rather than becoming impossible — but not one that competes
     * with the day's business.
     *
     * Ancestry, not presence: a collapsed `<details>` keeps its content in the
     * DOM, so `queryByLabelText` finds the verdict in both states. Only the
     * container distinguishes them.
     */
    it('keeps the verdict reachable from loop settings while the version runs', () => {
        render(
            <LoopShow
                intention={intention({ strategy: activeStrategy() })}
                strategies={[
                    strategy({
                        id: 7,
                        status: 'active',
                        verdict: null,
                        is_under_review: false,
                    }),
                ]}
                {...record}
            />,
        );

        const option = screen.getByLabelText(/it worked/i);

        expect(option.closest('[data-testid="loop-settings"]')).not.toBeNull();
        expect(option.closest('[data-testid="experiment-card"]')).toBeNull();
    });

    /**
     * Once the question is live it belongs on the page, not behind a tap. It
     * must not be in both places at once.
     */
    it('does not also keep the verdict in settings once it is under review', () => {
        render(
            <LoopShow
                intention={intention({ strategy: activeStrategy() })}
                strategies={[
                    strategy({
                        id: 7,
                        status: 'active',
                        verdict: null,
                        is_under_review: true,
                    }),
                ]}
                {...record}
            />,
        );

        expect(screen.getAllByLabelText(/it worked/i)).toHaveLength(1);
        expect(
            screen
                .getByLabelText(/it worked/i)
                .closest('[data-testid="experiment-card"]'),
        ).not.toBeNull();
    });
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx -t "loop settings"`
Expected: FAIL — no `loop-settings` container exists.

- [ ] **Step 3: Create the component**

Create `resources/js/patyourself/loops/loop-settings.tsx`. The `WorkflowPicker` body below is moved from `show.tsx` unchanged apart from losing its own `<details>` wrapper — nesting a disclosure inside a disclosure makes the second one unreachable in one tap for no gain.

```tsx
import { Form } from '@inertiajs/react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import { ConcludeExperimentForm } from '@/patyourself/loops/conclude-experiment-form';
import { StartExperimentForm } from '@/patyourself/loops/start-experiment-form';
import { Button } from '@/patyourself/primitives';
import { SectionHeading } from '@/patyourself/strategy-timeline';
import type { StrategyData } from '@/patyourself/types';

/** One workflow the loop may record through, straight from the server registry. */
export interface WorkflowOptionData {
    name: string;
    label: string;
}

interface LoopSettingsProps {
    loopId: number;
    workflow: string | null;
    workflows: WorkflowOptionData[];
    /**
     * The active unconcluded version, when the verdict is not already in the
     * experiment card. Undefined when there is none, or when the version is
     * under review and the card holds the question instead.
     */
    endableExperiment: StrategyData | undefined;
    canStartNext: boolean;
    currentCadence: string;
}

/**
 * The rare and consequential controls, in one place.
 *
 * These three used to sit loose in the column between the actions and the
 * outcome history, each competing for the same attention as the record itself:
 * a workflow picker, a start-the-next-experiment disclosure, and — before the
 * verdict was conditioned — a full verdict form near the top of the screen.
 * None of them is the day's business, and all three are hard to undo.
 *
 * One disclosure rather than three: a screen with three collapsed things on it
 * reads as three things you have not done.
 */
export function LoopSettings({
    loopId,
    workflow,
    workflows,
    endableExperiment,
    canStartNext,
    currentCadence,
}: LoopSettingsProps) {
    const showsRecording = workflows.length > 0;

    if (!showsRecording && !canStartNext && endableExperiment === undefined) {
        return null;
    }

    return (
        <details data-testid="loop-settings">
            <summary className="ds-label cursor-pointer">Loop settings</summary>

            <div className="mt-4 flex flex-col gap-6">
                {showsRecording && (
                    <Recording
                        loopId={loopId}
                        current={workflow}
                        workflows={workflows}
                    />
                )}

                {canStartNext && (
                    <section>
                        <SectionHeading>
                            Start the next experiment
                        </SectionHeading>
                        <StartExperimentForm
                            loopId={loopId}
                            currentCadence={currentCadence}
                        />
                    </section>
                )}

                {endableExperiment && (
                    <section>
                        <SectionHeading>
                            End this experiment early
                        </SectionHeading>
                        <ConcludeExperimentForm
                            strategyId={endableExperiment.id}
                            isUnderReview={false}
                        />
                    </section>
                )}
            </div>
        </details>
    );
}

/**
 * What this loop records, on top of whether it happened.
 *
 * "Nothing extra" is the first option and the one a loop already has:
 * recording nothing extra is what almost every loop does, forever, and the
 * control must not read as a field left blank.
 *
 * The options come from the server registry and are never typed. A free-text
 * tag was the earlier answer and it failed silently on `Gym`, `gimnasio` or a
 * trailing space, with nothing on screen to say why — see
 * `UpdateIntentionRequest`, which validates against the same list this is
 * drawn from.
 */
function Recording({
    loopId,
    current,
    workflows,
}: {
    loopId: number;
    current: string | null;
    workflows: WorkflowOptionData[];
}) {
    return (
        <section data-testid="workflow-picker">
            <SectionHeading>Recording</SectionHeading>

            <Form
                {...update.form(loopId)}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-2"
            >
                {({ processing }) => (
                    <>
                        <label htmlFor="loop-workflow" className="sr-only">
                            What this loop records
                        </label>
                        <select
                            id="loop-workflow"
                            name="workflow"
                            defaultValue={current ?? ''}
                            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Nothing extra</option>
                            {workflows.map((workflow) => (
                                <option
                                    key={workflow.name}
                                    value={workflow.name}
                                >
                                    {workflow.label}
                                </option>
                            ))}
                        </select>

                        <p className="text-xs text-muted-foreground">
                            Most loops record nothing extra — the outcome is the
                            whole record. A workflow adds somewhere to write
                            down what happened during an occasion. Changing this
                            keeps everything already written.
                        </p>

                        <div className="self-start">
                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </section>
    );
}
```

- [ ] **Step 4: Wire it into `show.tsx`**

1. Delete the local `WorkflowPicker` function and the local `WorkflowOptionData` interface.
2. Delete the `{intention.strategy && (<details>…StartExperimentForm…</details>)}` block.
3. Delete the `<WorkflowPicker … />` call.
4. Imports: drop `StartExperimentForm` and `Form` if now unused (`grep -n '<Form' resources/js/pages/loops/show.tsx` — the activate-loop form still uses it, so `Form` stays); add:

```tsx
import { LoopSettings, type WorkflowOptionData } from '@/patyourself/loops/loop-settings';
```

5. Render it in the position Task 5 fixes; for now put it where `WorkflowPicker` was:

```tsx
<LoopSettings
    loopId={intention.id}
    workflow={intention.workflow}
    workflows={workflows}
    endableExperiment={
        activeExperiment?.is_under_review === false
            ? activeExperiment
            : undefined
    }
    canStartNext={intention.strategy !== null}
    currentCadence={currentCadenceLabel(intention.active_action ?? null)}
/>
```

- [ ] **Step 5: Run the tests, and expect two existing ones to need a look**

```bash
npx vitest run resources/js/pages/loops/show.test.tsx
```

`does not offer to start the next experiment without an active strategy` asserts `queryByText(/start the next experiment/i)` is absent when `intention.strategy` is null. `canStartNext` is `intention.strategy !== null`, so it still passes.

`offers to start the next experiment behind a disclosure when a strategy is active` asserts the text is present. A collapsed `<details>` keeps content in the DOM, so it still passes.

The four workflow-picker cases query by label and by `closest('form')`, both of which survive the move. They should pass unchanged. **If any of these four fail, fix the test rather than the component only after confirming the component's behaviour is genuinely unchanged** — the picker's markup is moved verbatim.

- [ ] **Step 6: Register the new file for the vocabulary scan**

```php
$root.'/resources/js/patyourself/loops/loop-settings.tsx',
```

- [ ] **Step 7: Full check**

```bash
npx vitest run
php artisan test --compact --filter=CompanionVocabularyTest
npx tsc --noEmit
```

- [ ] **Step 8: Commit**

```bash
git add resources/js/patyourself/loops/loop-settings.tsx resources/js/pages/loops/show.tsx resources/js/pages/loops/show.test.tsx tests/Feature/Companion/CompanionVocabularyTest.php
git commit -m "feat(loops): collect the rare controls into loop settings"
```

---

### Task 5: The order

Everything is now a component. This task is the reordering the whole plan exists for.

**Files:**
- Modify: `resources/js/pages/loops/show.tsx`
- Modify: `resources/js/pages/loops/show.test.tsx`

- [ ] **Step 1: Write the failing test**

Replace `leads with the experiment and the reflection` (currently line 243) with:

```tsx
    /**
     * The screen is ordered by how often each block is the reason you opened
     * it. The experiment leads because "what am I testing and how is it going"
     * is every visit; the actions follow because tending them is frequent; the
     * anatomy sits below both because it changes perhaps twice in a loop's
     * life.
     *
     * Asserted as document order rather than by reading the markup, so a
     * future edit that moves a block has to move this expectation with it.
     *
     * Killing mutation: swap the anatomy above the actions — the ordering
     * assertion fails. Restore the old order entirely and it fails at the
     * first pair.
     */
    it('orders the screen by how often each block is wanted', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({ id: 7, status: 'active', verdict: null }),
                ]}
                {...record}
                actions={[actionRecord()]}
                current_version={currentVersion()}
                experiments={[]}
                reflection={{
                    content: 'Lunch is where it goes.',
                    window_start: '2026-08-13T00:00:00+00:00',
                    window_end: '2026-08-27T00:00:00+00:00',
                    events_count: 28,
                }}
            />,
        );

        const card = screen.getByTestId('experiment-card');
        const actionsHeading = screen.getByText(/^actions$/i);
        const anatomy = screen.getByTestId('habit-anatomy');
        const reflectionHeading = screen.getByText(/what the record shows/i);
        const settings = screen.getByTestId('loop-settings');

        const follows = (earlier: Element, later: Element) =>
            Boolean(
                earlier.compareDocumentPosition(later) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            );

        expect(follows(card, actionsHeading)).toBe(true);
        expect(follows(actionsHeading, anatomy)).toBe(true);
        expect(follows(anatomy, reflectionHeading)).toBe(true);
        expect(follows(reflectionHeading, settings)).toBe(true);
    });

    /**
     * The note form and the notes it produces are one block. They used to be
     * two unheaded siblings at the very bottom, below the outcome history.
     */
    it('gives the notes a heading of their own', () => {
        render(
            <LoopShow intention={intention()} strategies={[]} {...record} />,
        );

        expect(screen.getByText(/^notes$/i)).toBeInTheDocument();
    });
```

- [ ] **Step 2: Run it and watch it fail**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx -t "orders the screen"`
Expected: FAIL — the anatomy currently precedes the actions.

- [ ] **Step 3: Reorder the JSX**

The body of the returned `<div className="flex flex-col gap-6">` becomes, in this order:

1. The type/status badges `<section>` — unchanged.
2. The paused-loop activate `<Form>` — unchanged.
3. The description `<p>` — unchanged.
4. `<ExperimentCard … />`
5. The actions `<section>` with its `SectionHeading` and `<ActionLayer … />` — unchanged internally.
6. `<Anatomy … />`
7. `<Reflection reflection={reflection} />`
8. `<StrategyTimeline … />`
9. `<OutcomeHistory … />`
10. The notes block — wrap the existing two components in one section:

```tsx
<section>
    <SectionHeading>Notes</SectionHeading>
    <NoteForm loopId={intention.id} />
    <LoopNotes notes={notes} />
</section>
```

11. `<LoopSettings … />`

- [ ] **Step 4: Update the page docblock**

The docblock on `LoopShow` describes the old order and the old intent. Replace its second and third paragraphs with:

```
 * Ordered by how often each block is the reason the screen was opened: the
 * experiment first, the actions that carry it second, and the anatomy below
 * both — it is the loop's identity but it changes perhaps twice in a loop's
 * life, so it is disclosed behind the chain that names it rather than drawn.
 *
 * The timeline and the history sit on one screen deliberately — comparing what
 * was tried against what happened is the whole point of a notebook.
 *
 * Read-only: history is only ever appended to, and outcomes are logged from
 * the catch-up screen or the conversation. The actions here are configured,
 * not recorded against.
```

- [ ] **Step 5: Run the tests**

Run: `npx vitest run resources/js/pages/loops/show.test.tsx`
Expected: PASS.

- [ ] **Step 6: Full verification**

```bash
npm run build && php artisan test --compact && npx vitest run \
  && npx tsc --noEmit && vendor/bin/pint --dirty --format agent && npm run lint
```

Expected: 1015 PHP tests / 6380 assertions, JS above 467, 0 TypeScript errors, pint and eslint clean.

- [ ] **Step 7: Look at it**

Herd serves the main checkout, never a worktree. From the worktree:

```bash
php -S 127.0.0.1:8899 -t public
```

Open `http://127.0.0.1:8899/loops/{id}` on a loop that has an active experiment and at least one action. Confirm by eye: the experiment card leads, the actions follow, the chain is one line, and the verdict is not on the page. Class-name assertions and DOM order cannot judge a layout — this project has shipped a component into the wrong corner with 340 tests green.

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/loops/show.tsx resources/js/pages/loops/show.test.tsx
git commit -m "feat(loops): order the loop screen by what it is opened for"
```

---

## Self-review

**Spec coverage.** §4 order → Task 5. §4.1 experiment card → Task 2. §4.2 conditional verdict → Tasks 2 and 4. §4.3 chain → Task 1. §4.4 loop settings → Task 4. §5 files → all five tasks; `strategy-timeline.tsx` → Task 3. §6 testing → each task's own steps, plus the two existing cases named in Task 2 Step 6 and the four checked in Task 4 Step 5.

**One correction to the spec, found while planning.** §6 claims the four workflow-picker cases "need updating for nesting" and that a new assertion should check the verdict is "absent from the page" when not under review. Both are wrong: a collapsed `<details>` keeps its content in the DOM, so those four cases pass unmoved, and an absence assertion would pass for the wrong reason. Every verdict-placement assertion in this plan tests ancestry instead. The spec's §6 is corrected in the same commit as this plan.

**Type consistency.** `activeExperiment` is `StrategyData | undefined` in `show.tsx`, `ExperimentCard` and `LoopSettings`. `activeVersion` is `number | null` in `StrategyTimeline`. `WorkflowOptionData` has one definition, in `loop-settings.tsx`, re-exported and imported by `show.tsx`. `ConcludeExperimentForm` keeps `{ strategyId: number; isUnderReview: boolean }` at both mount points.
