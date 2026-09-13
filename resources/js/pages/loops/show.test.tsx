import type * as InertiaReact from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type {
    ActionRecordData,
    ActiveActionData,
    ActiveStrategySummary,
    CurrentVersionData,
    ExperimentData,
    IntentionData,
    StrategyData,
} from '@/patyourself/types';

const page = { url: '/loops/1', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import LoopShow from './show';

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

/** The record props every render needs; individual tests override what they care about. */
const record = {
    outcomes: [],
    outcomes_total: 0,
    showing_all_history: false,
    notes: [],
    actions: [],
};

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

function experiment(overrides: Partial<ExperimentData> = {}): ExperimentData {
    return {
        strategy_id: 1,
        version: 1,
        status: 'superseded',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        hypothesis: null,
        started_at: '2026-08-01T09:00:00+00:00',
        review_at: null,
        day_of_experiment: 14,
        planned_days: 14,
        is_under_review: false,
        verdict: 'failed',
        verdict_note: null,
        outcomes: [],
        totals: { completed: 0, failed: 0, skipped: 0 },
        ...overrides,
    };
}

function activeStrategy(
    overrides: Partial<ActiveStrategySummary> = {},
): ActiveStrategySummary {
    return {
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        version: 1,
        day_of_experiment: 5,
        planned_days: 14,
        is_under_review: false,
        ...overrides,
    };
}

function activeAction(
    overrides: Partial<ActiveActionData> = {},
): ActiveActionData {
    return {
        id: 1,
        title: 'Read ten pages',
        description: null,
        next_occurrence_at: null,
        recurrence: null,
        schedule_kind: null,
        anchor: null,
        ...overrides,
    };
}

function actionRecord(
    overrides: Partial<ActionRecordData> = {},
): ActionRecordData {
    return {
        id: 1,
        title: 'Read ten pages',
        next_occurrence_at: null,
        recurrence: null,
        schedule_kind: null,
        anchor: null,
        ...overrides,
    };
}

function strategy(overrides: Partial<StrategyData> = {}): StrategyData {
    return {
        id: 1,
        version: 1,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        rationale: null,
        change_reason: null,
        superseded_reason: null,
        review_at: null,
        verdict: null,
        verdict_note: null,
        day_of_experiment: 5,
        planned_days: 14,
        is_under_review: false,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('LoopShow', () => {
    it('offers to activate a paused loop', () => {
        render(
            <LoopShow
                intention={intention({ status: 'paused' })}
                strategies={[]}
                {...record}
            />,
        );

        expect(
            screen.getByRole('button', { name: /activate/i }),
        ).toBeInTheDocument();
    });

    it('does not offer activation for an active loop', () => {
        render(
            <LoopShow
                intention={intention({ status: 'active' })}
                strategies={[]}
                {...record}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /activate/i }),
        ).not.toBeInTheDocument();
    });

    it('uses the design-system Button for the activate action', () => {
        render(
            <LoopShow
                intention={intention({ status: 'paused' })}
                strategies={[]}
                {...record}
            />,
        );

        const button = screen.getByRole('button', { name: /activate/i });
        expect(button).toHaveClass('py-btn', 'py-btn--primary');
    });

    it('credits Claude only for a loop it authored', () => {
        render(
            <LoopShow
                intention={intention({
                    status: 'paused',
                    metadata: { authored_by: 'mcp-client' },
                })}
                strategies={[]}
                {...record}
            />,
        );

        expect(
            screen.getByText(/claude drafted this loop/i),
        ).toBeInTheDocument();
    });

    it('does not credit Claude for a paused loop it did not author', () => {
        render(
            <LoopShow
                intention={intention({
                    status: 'paused',
                    metadata: { authored_by: 'user' },
                })}
                strategies={[]}
                {...record}
            />,
        );

        expect(
            screen.queryByText(/claude drafted this loop/i),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                /activating it starts its schedule and notifications/i,
            ),
        ).toBeInTheDocument();
    });

    /**
     * The screen is ordered by how often each block is the reason you opened
     * it. The experiment leads because "what am I testing and how is it going"
     * is every visit; the actions follow because tending them is frequent; the
     * anatomy sits below both because it changes perhaps twice in a loop's
     * life.
     *
     * Asserted as the full adjacent chain, end to end, rather than a few
     * sampled pairs — a gap anywhere in the middle would let a block drift
     * without any assertion noticing. A superseded version rides alongside
     * the active one so "Past experiments" actually renders; without it
     * `StrategyTimeline` returns null and that link in the chain has no
     * handle to grab.
     *
     * Killing mutation: swap any adjacent pair in `show.tsx` — for instance
     * `StrategyTimeline` and `OutcomeHistory` — and the corresponding
     * assertion fails. Restore the old order entirely and it fails at the
     * first pair.
     */
    it('orders the screen by how often each block is wanted', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({
                        id: 7,
                        version: 2,
                        status: 'active',
                        verdict: null,
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
                actions={[actionRecord()]}
                current_version={currentVersion({ version: 2 })}
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
        const pastExperiments = screen.getByText(/past experiments/i);
        const outcomeHistory = screen.getByText(/outcome history/i);
        const notes = screen.getByTestId('notes');
        const settings = screen.getByTestId('loop-settings');

        const follows = (earlier: Element, later: Element) =>
            Boolean(
                earlier.compareDocumentPosition(later) &
                Node.DOCUMENT_POSITION_FOLLOWING,
            );

        expect(follows(card, actionsHeading)).toBe(true);
        expect(follows(actionsHeading, anatomy)).toBe(true);
        expect(follows(anatomy, reflectionHeading)).toBe(true);
        expect(follows(reflectionHeading, pastExperiments)).toBe(true);
        expect(follows(pastExperiments, outcomeHistory)).toBe(true);
        expect(follows(outcomeHistory, notes)).toBe(true);
        expect(follows(notes, settings)).toBe(true);
        expect(screen.getByText(/lunch is where it goes/i)).toBeInTheDocument();
    });

    /**
     * The note form and the notes it produces are one block. They used to be
     * two siblings at the very bottom, below the outcome history: the list
     * carried its own heading, the form carried nothing, and neither knew
     * about the other.
     *
     * Asserted as containment rather than by looking for a heading. `LoopNotes`
     * renders its own "Notes" heading and always did, so any assertion that
     * merely finds one passes whether or not this block exists — and
     * `getNodeText` reads only direct text-node children, so the count span
     * does not stop `/^notes$/i` matching it.
     *
     * Killing mutation: drop the wrapping section, or move either child out
     * of it.
     */
    it('keeps the note form and its notes in one block', () => {
        render(
            <LoopShow intention={intention()} strategies={[]} {...record} />,
        );

        const block = screen.getByTestId('notes');

        expect(block).toContainElement(
            screen.getByPlaceholderText('Something you noticed'),
        );
        expect(block).toContainElement(screen.getByText(/^notes$/i));
    });

    /**
     * The comparison is against the version immediately before this one, not
     * the loop's lifetime and not its oldest experiment.
     *
     * Two prior versions on purpose, with different rates: with only one, an
     * implementation that picked the oldest would be indistinguishable from one
     * that picked the previous, and the test would prove nothing.
     */
    it('compares the current version against the one immediately before it', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                current_version={currentVersion({
                    version: 3,
                    completion_rate: 68,
                    totals: { completed: 15, failed: 7, skipped: 0 },
                })}
                experiments={[
                    experiment({
                        version: 1,
                        // 90% — the oldest. Picking this would be wrong.
                        totals: { completed: 9, failed: 1, skipped: 0 },
                    }),
                    experiment({
                        version: 2,
                        // 41% — the one that actually preceded v3.
                        totals: { completed: 9, failed: 13, skipped: 0 },
                    }),
                ]}
            />,
        );

        const delta = screen.getByTestId('experiment-delta');

        expect(delta).toHaveTextContent(/up from 41%/i);
        expect(delta).not.toHaveTextContent(/90%/);
    });

    /**
     * A previous version that was never tested is not a trend. Comparing against
     * it would invent one out of nothing, so the delta is omitted.
     */
    it('omits the delta when the previous version was never tested', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                current_version={currentVersion({ version: 2 })}
                experiments={[
                    experiment({
                        version: 1,
                        totals: { completed: 0, failed: 0, skipped: 0 },
                    }),
                ]}
            />,
        );

        expect(
            screen.queryByTestId('experiment-delta'),
        ).not.toBeInTheDocument();
    });

    it('says so plainly when no experiment is running', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                current_version={null}
                experiments={[]}
                reflection={null}
            />,
        );

        expect(screen.getByText(/logging continues/i)).toBeInTheDocument();
        expect(
            screen.getByText(/no reflection written yet/i),
        ).toBeInTheDocument();
    });

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

    it('does not offer a verdict for a version already concluded', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({ id: 7, status: 'active', verdict: 'worked' }),
                ]}
                {...record}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
    });

    it('does not offer a verdict when no version is active', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({
                        id: 7,
                        status: 'superseded',
                        verdict: 'failed',
                    }),
                ]}
                {...record}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
    });

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

    /**
     * Starting the next experiment supersedes the currently active one, so
     * the disclosure that leads to it only makes sense when there is an
     * active strategy to supersede.
     */
    it('does not offer to start the next experiment without an active strategy', () => {
        render(
            <LoopShow intention={intention()} strategies={[]} {...record} />,
        );

        expect(
            screen.queryByText(/start the next experiment/i),
        ).not.toBeInTheDocument();
        expect(screen.queryByTestId('loop-settings')).not.toBeInTheDocument();
    });

    it('offers to start the next experiment behind a disclosure when a strategy is active', () => {
        render(
            <LoopShow
                intention={intention({ strategy: activeStrategy() })}
                strategies={[]}
                {...record}
            />,
        );

        expect(
            screen.getByText(/start the next experiment/i),
        ).toBeInTheDocument();
    });

    /**
     * The keep option names the active action's cadence so inheriting it is a
     * legible choice rather than a hidden default — see StartExperimentForm.
     * The anchored kind needs no time formatting, so it is the deterministic
     * case to prove the label reaches the form at all.
     */
    it('names an anchored cadence in the keep option', async () => {
        render(
            <LoopShow
                intention={intention({
                    strategy: activeStrategy(),
                    active_action: activeAction({
                        schedule_kind: 'anchored',
                        anchor: 'brushing your teeth',
                    }),
                })}
                strategies={[]}
                {...record}
            />,
        );

        await userEvent.click(screen.getByText(/start the next experiment/i));

        expect(
            screen.getByLabelText(
                /keep the current cadence \(after brushing your teeth\)/i,
            ),
        ).toBeInTheDocument();
    });

    /** Same wiring, exercised through the clock kind. */
    it('names a clock cadence in the keep option', async () => {
        const nextOccurrenceAt = '2026-08-18T19:00:00+00:00';
        const time = new Date(nextOccurrenceAt).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        render(
            <LoopShow
                intention={intention({
                    strategy: activeStrategy(),
                    active_action: activeAction({
                        schedule_kind: 'clock',
                        recurrence: 'daily',
                        next_occurrence_at: nextOccurrenceAt,
                    }),
                })}
                strategies={[]}
                {...record}
            />,
        );

        await userEvent.click(screen.getByText(/start the next experiment/i));

        expect(
            screen.getByLabelText(
                new RegExp(
                    `keep the current cadence \\(daily at ${time}\\)`,
                    'i',
                ),
            ),
        ).toBeInTheDocument();
    });

    /**
     * Without an active action there is nothing to name — the option must
     * read cleanly, not with a dangling "()".
     */
    it('reads the keep option without empty parentheses when there is no active action', async () => {
        render(
            <LoopShow
                intention={intention({ strategy: activeStrategy() })}
                strategies={[]}
                {...record}
            />,
        );

        await userEvent.click(screen.getByText(/start the next experiment/i));

        expect(
            screen.getByLabelText(/^keep the current cadence$/i),
        ).toBeInTheDocument();
    });

    it('mounts the note form above the notes list', () => {
        render(
            <LoopShow intention={intention()} strategies={[]} {...record} />,
        );

        expect(
            screen.getByPlaceholderText(/something you noticed/i),
        ).toBeInTheDocument();
    });

    it('lists a live action with its cadence in the action layer', () => {
        const nextOccurrenceAt = '2026-08-18T19:00:00+00:00';
        const time = new Date(nextOccurrenceAt).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[
                    actionRecord({
                        id: 9,
                        title: 'Weigh in',
                        schedule_kind: 'clock',
                        recurrence: 'daily',
                        next_occurrence_at: nextOccurrenceAt,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('Weigh in')).toBeInTheDocument();
        expect(screen.getByText(`daily at ${time}`)).toBeInTheDocument();
    });

    /**
     * The defect fixed in this task: the brief's own Step 8 would have
     * formatted this as "daily at " with nothing after it. An action with a
     * recurrence but no occurrence left in today's grid must read as the
     * recurrence alone.
     */
    it('does not render a dangling cadence for an action with no occurrence left to report', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[
                    actionRecord({
                        id: 9,
                        title: 'Weigh in',
                        schedule_kind: 'clock',
                        recurrence: 'daily',
                        next_occurrence_at: null,
                    }),
                ]}
            />,
        );

        expect(screen.getByText('daily')).toBeInTheDocument();
        expect(screen.queryByText(/daily at/i)).not.toBeInTheDocument();
    });

    /**
     * An action's wording and cadence were unreachable in the app — the coach
     * could change both over MCP and the owner could change neither.
     *
     * Tap to edit rather than always-live inputs: a mis-tap on a phone must not
     * silently rename an action or move its schedule.
     *
     * Killing mutation: render the edit form unconditionally. The first
     * assertion fails, because the title input would exist before Edit is
     * pressed.
     */
    it('edits an action only after the edit control is pressed', async () => {
        const user = userEvent.setup();

        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[actionRecord({ id: 3, title: 'Upper body 2' })]}
            />,
        );

        expect(screen.queryByTestId('action-editor-3')).not.toBeInTheDocument();

        await user.click(
            screen.getByRole('button', { name: /edit upper body 2/i }),
        );

        const editor = within(screen.getByTestId('action-editor-3'));
        const title = editor.getByLabelText(/what to do/i);

        expect(title).toHaveValue('Upper body 2');
        expect(title.closest('form')).toHaveAttribute(
            'action',
            expect.stringContaining('/actions/3'),
        );
    });

    /**
     * Cancel restores the read state without a request. Asserted because the
     * alternative — leaving the form open — is what makes an accidental Edit
     * press feel like a trap.
     */
    it('closes the action edit form on cancel', async () => {
        const user = userEvent.setup();

        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[actionRecord({ id: 3, title: 'Upper body 2' })]}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: /edit upper body 2/i }),
        );
        await user.click(screen.getByRole('button', { name: /cancel/i }));

        expect(screen.queryByTestId('action-editor-3')).not.toBeInTheDocument();
    });

    /**
     * Editing state is per action, not per layer. Two actions on a loop, press
     * Edit on one, and only that one opens.
     *
     * Killing mutation: hoist the editing flag to a boolean on ActionLayer
     * instead of holding the action's id. Both editors open and the second
     * assertion fails.
     */
    it('opens only the pressed action’s editor', async () => {
        const user = userEvent.setup();

        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
                actions={[
                    actionRecord({ id: 3, title: 'Upper body 2' }),
                    actionRecord({ id: 4, title: 'Lower body 1' }),
                ]}
            />,
        );

        await user.click(
            screen.getByRole('button', { name: /edit upper body 2/i }),
        );

        expect(screen.getByTestId('action-editor-3')).toBeInTheDocument();
        expect(screen.queryByTestId('action-editor-4')).not.toBeInTheDocument();
    });

    describe('the workflow picker', () => {
        const WORKFLOWS = [{ name: 'gym', label: 'Gym' }];

        /**
         * Recording nothing extra is what almost every loop does, forever, so
         * it has to be the ordinary, already-selected option — not a field
         * left blank that the control implies should be filled.
         *
         * Killing mutation: drop the `<option value="">Nothing extra</option>`
         * and default the select to the first registered workflow. A plain
         * loop's picker would show "Gym" selected, and both assertions below
         * fail — verified by direct mutation and rerun.
         */
        it('offers recording nothing as the selected default for a plain loop', () => {
            render(
                <LoopShow
                    intention={intention({ workflow: null })}
                    strategies={[]}
                    {...record}
                    workflows={WORKFLOWS}
                />,
            );

            const select = screen.getByLabelText('What this loop records');

            expect(select).toHaveValue('');
            expect(
                screen.getByRole('option', { name: 'Nothing extra' }),
            ).toBeInTheDocument();
        });

        /**
         * The options come from the server registry, never from a list held
         * here — `UpdateIntentionRequest` validates against the same registry,
         * so a client-side copy could only ever drift out of agreement.
         *
         * Killing mutation: hardcode the options to `['gym']` and ignore the
         * prop. This test's own registry entry would not appear and the
         * assertion fails — verified by direct mutation and rerun.
         */
        it('draws its options from the registry the server sent, not a list of its own', () => {
            render(
                <LoopShow
                    intention={intention({ workflow: null })}
                    strategies={[]}
                    {...record}
                    workflows={[{ name: 'brewing', label: 'Brewing' }]}
                />,
            );

            expect(
                screen.getByRole('option', { name: 'Brewing' }),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('option', { name: 'Gym' }),
            ).not.toBeInTheDocument();
        });

        /**
         * Killing mutation: default the select to `''` regardless of the
         * loop's own workflow. A gym loop would open its picker showing
         * "Nothing extra" and this assertion fails — verified by direct
         * mutation and rerun.
         */
        it('shows the loop’s current workflow as selected', () => {
            render(
                <LoopShow
                    intention={intention({ workflow: 'gym' })}
                    strategies={[]}
                    {...record}
                    workflows={WORKFLOWS}
                />,
            );

            expect(screen.getByLabelText('What this loop records')).toHaveValue(
                'gym',
            );
        });

        /**
         * `?_method=PUT` is Wayfinder's form variant spoofing the verb, since
         * a browser form can only ever be GET or POST. That is part of the
         * contract with `loops.update`, so it is asserted rather than trimmed
         * away.
         *
         * Killing mutation: point the picker's form at a route other than the
         * loop update one (or drop the `name="workflow"` off the select).
         * Either way the choice would never reach `UpdateIntentionRequest` —
         * verified by direct mutation and rerun.
         */
        it('saves the choice to the loop update route', () => {
            render(
                <LoopShow
                    intention={intention({ id: 4, workflow: null })}
                    strategies={[]}
                    {...record}
                    workflows={WORKFLOWS}
                />,
            );

            const select = screen.getByLabelText('What this loop records');
            const form = select.closest('form');

            expect(form).toHaveAttribute('action', '/loops/4?_method=PUT');
            expect(select).toHaveAttribute('name', 'workflow');
        });
    });

    describe('the routine editor', () => {
        /**
         * A plain loop must look exactly as it does today: the action layer is
         * shared by every loop in the app, and the configuration surface is
         * additive or it is a regression.
         *
         * Killing mutation: have `show.tsx` hand `ActionLayer` a hardcoded
         * workflow instead of the loop's own, so the routine editor renders
         * off `routine` alone. The plain loop's action below is given a
         * routine for exactly this reason — without one, the mutation has
         * nothing to render and this test would still pass — verified by
         * direct mutation and rerun.
         */
        it('draws nothing on a plain loop’s action', () => {
            render(
                <LoopShow
                    intention={intention({ workflow: null })}
                    strategies={[]}
                    {...record}
                    actions={[
                        actionRecord({
                            id: 9,
                            title: 'Upper A',
                            routine: [
                                {
                                    id: 1,
                                    exercise_id: 5,
                                    exercise_name: 'Bench Press',
                                    position: 1,
                                    target_sets: 3,
                                    target_reps: 10,
                                },
                            ],
                        }),
                    ]}
                />,
            );

            expect(
                screen.queryByTestId('routine-editor-9'),
            ).not.toBeInTheDocument();
        });

        /**
         * Killing mutation: pass `workflow={null}` into `ActionLayer` rather
         * than the loop's own. The editor would never draw for anyone and this
         * assertion fails — verified by direct mutation and rerun.
         */
        it('draws on a gym loop’s action, beside the action itself', () => {
            render(
                <LoopShow
                    intention={intention({ workflow: 'gym' })}
                    strategies={[]}
                    {...record}
                    actions={[
                        actionRecord({
                            id: 9,
                            title: 'Upper A',
                            routine: [
                                {
                                    id: 1,
                                    exercise_id: 5,
                                    exercise_name: 'Bench Press',
                                    position: 1,
                                    target_sets: 3,
                                    target_reps: 10,
                                },
                            ],
                        }),
                    ]}
                />,
            );

            expect(screen.getByTestId('routine-editor-9')).toBeInTheDocument();
            expect(screen.getByText('Bench Press')).toBeInTheDocument();
        });
    });

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

        expect(screen.getAllByText(/lay your shoes by the door/i)).toHaveLength(
            1,
        );
        expect(
            screen.getByText(/put the book on the pillow/i),
        ).toBeInTheDocument();
        expect(screen.getByText(/past experiments/i)).toBeInTheDocument();
    });

    /**
     * Concluding does not supersede: a version concluded as `worked` stays
     * active and keeps running. It is still the experiment the screen is
     * about, so the card must still carry its hypothesis and past experiments
     * must not claim it.
     *
     * Killing mutation: source the card's hypothesis or the timeline's
     * exclusion from a version that requires `verdict === null`, and this
     * goes red on both counts.
     */
    it('still treats a version concluded as worked as the running experiment', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({
                        id: 7,
                        version: 2,
                        status: 'active',
                        verdict: 'worked',
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
            screen
                .getByText(/lay your shoes by the door/i)
                .closest('[data-testid="experiment-card"]'),
        ).not.toBeNull();
        expect(screen.getAllByText(/lay your shoes by the door/i)).toHaveLength(
            1,
        );
        expect(
            screen.getByText(/put the book on the pillow/i),
        ).toBeInTheDocument();
    });

    /**
     * The question is closed once a verdict is recorded, so neither the card
     * nor loop settings may ask it again — even though the version is still
     * the running one.
     */
    it('does not offer to end a version that already has a verdict', () => {
        render(
            <LoopShow
                intention={intention({ strategy: activeStrategy() })}
                strategies={[
                    strategy({ id: 7, status: 'active', verdict: 'worked' }),
                ]}
                {...record}
            />,
        );

        expect(screen.queryByLabelText(/it worked/i)).not.toBeInTheDocument();
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
                    strategy({
                        id: 7,
                        version: 1,
                        status: 'active',
                        verdict: null,
                    }),
                ]}
                {...record}
                current_version={currentVersion({ version: 1 })}
            />,
        );

        expect(screen.queryByText(/past experiments/i)).not.toBeInTheDocument();
    });
});
