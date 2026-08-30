import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type {
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

    it('leads with the experiment and the reflection', () => {
        render(
            <LoopShow
                intention={intention()}
                strategies={[]}
                {...record}
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

        expect(screen.getByTestId('experiment-state')).toHaveTextContent(
            /day 9 of 14/i,
        );
        expect(screen.getByText(/what the record shows/i)).toBeInTheDocument();
        expect(screen.getByText(/lunch is where it goes/i)).toBeInTheDocument();
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
     * The active, not-yet-concluded version is the one the record can still
     * answer a review for. `status === 'active'` alone is not enough — a
     * `worked` verdict leaves a version active while the question is closed.
     */
    it('offers a verdict for the active, unconcluded version', () => {
        const { container } = render(
            <LoopShow
                intention={intention()}
                strategies={[
                    strategy({ id: 7, status: 'active', verdict: null }),
                ]}
                {...record}
            />,
        );

        expect(screen.getByLabelText(/it worked/i)).toBeInTheDocument();
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
});
