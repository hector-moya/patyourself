import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { ExperimentData, StrategyData } from '@/patyourself/types';
import { StrategyTimeline } from './strategy-timeline';

function experiment(overrides: Partial<ExperimentData> = {}): ExperimentData {
    return {
        strategy_id: 1,
        version: 1,
        status: 'active',
        intervention_point: 'cue',
        approach: 'Lay your shoes by the door',
        hypothesis: null,
        started_at: '2026-08-01T09:00:00+00:00',
        review_at: null,
        day_of_experiment: 3,
        planned_days: null,
        is_under_review: false,
        verdict: null,
        verdict_note: null,
        outcomes: [],
        totals: { completed: 0, failed: 0, skipped: 0 },
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
        change_reason: 'initial',
        superseded_reason: null,
        review_at: null,
        verdict: null,
        verdict_note: null,
        day_of_experiment: 0,
        planned_days: null,
        is_under_review: false,
        parent_strategy_id: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        ...overrides,
    };
}

describe('StrategyTimeline', () => {
    it('renders a node per version with its change-reason copy', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({
                        id: 1,
                        version: 1,
                        status: 'superseded',
                        superseded_reason: 'kept missing it',
                    }),
                    strategy({
                        id: 2,
                        version: 2,
                        status: 'active',
                        change_reason: 'restrategized_on_failure',
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/v1/)).toBeInTheDocument();
        expect(screen.getByText(/v2/)).toBeInTheDocument();
        expect(
            screen.getByText('Restrategized after a setback'),
        ).toBeInTheDocument();
        expect(screen.getByText('“kept missing it”')).toBeInTheDocument();
    });

    it('shows an empty state when there are no strategies', () => {
        render(<StrategyTimeline strategies={[]} />);

        expect(screen.getByText(/no strategy yet/i)).toBeInTheDocument();
    });

    it('reports the day of a running experiment', () => {
        render(
            <StrategyTimeline
                strategies={[strategy({ day_of_experiment: 3 })]}
            />,
        );

        expect(screen.getByText(/day 3/i)).toBeInTheDocument();
    });

    it('never renders a countdown for an open-ended experiment', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({ day_of_experiment: 3, planned_days: null }),
                ]}
            />,
        );

        // planned_days: null means open-ended — not a zero-day experiment, and
        // not something to count down. "The notebook never nags."
        expect(screen.queryByText(/of 0/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/day 3 of/i)).not.toBeInTheDocument();
        expect(screen.getByText(/open-ended/i)).toBeInTheDocument();
    });

    it('renders the planned length when the experiment has one', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({ day_of_experiment: 3, planned_days: 21 }),
                ]}
            />,
        );

        expect(screen.getByText(/day 3 of 21/i)).toBeInTheDocument();
    });

    it('renders a verdict in strategy-facing language', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({
                        status: 'superseded',
                        verdict: 'failed',
                        verdict_note: 'the cue never fired',
                    }),
                ]}
            />,
        );

        expect(screen.getByText(/did not hold/i)).toBeInTheDocument();
        expect(screen.getByText(/the cue never fired/i)).toBeInTheDocument();
        // Never about the user.
        expect(screen.queryByText(/you failed/i)).not.toBeInTheDocument();
    });

    it('distinguishes a version that was never tested from one that failed', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({ id: 1, version: 1, outcomes_recorded: 0 }),
                    strategy({ id: 2, version: 2, outcomes_recorded: 11 }),
                ]}
            />,
        );

        expect(screen.getByText(/not yet tested/i)).toBeInTheDocument();
        expect(screen.getByText(/11 outcomes/i)).toBeInTheDocument();
    });

    it('omits the evidence line when the count was not supplied', () => {
        render(<StrategyTimeline strategies={[strategy()]} />);

        expect(screen.queryByText(/not yet tested/i)).not.toBeInTheDocument();
    });

    it('marks an experiment that is ready to conclude', () => {
        render(
            <StrategyTimeline
                strategies={[strategy({ is_under_review: true })]}
            />,
        );

        expect(screen.getByText(/ready to conclude/i)).toBeInTheDocument();
    });

    it('heads the section as experiments, the unit of the app', () => {
        render(<StrategyTimeline strategies={[strategy()]} />);

        expect(screen.getByText(/experiments/i)).toBeInTheDocument();
    });

    /**
     * The evidence per version, which is the comparison that says whether
     * changing the strategy changed anything. Raw counts lead and the rate
     * follows: with a handful of logs a percentage hides its own denominator.
     */
    it('leads with the raw counts and follows with the rate', () => {
        render(
            <StrategyTimeline
                strategies={[strategy({ version: 1 })]}
                experiments={[
                    experiment({
                        version: 1,
                        totals: { completed: 9, failed: 13, skipped: 4 },
                    }),
                ]}
            />,
        );

        const evidence = screen.getByTestId('experiment-evidence-1');

        expect(evidence).toHaveTextContent(/9 of 22 held/i);
        // skipped is never in the denominator — 9+13, not 9+13+4.
        expect(evidence).not.toHaveTextContent(/of 26/);
        expect(evidence).toHaveTextContent(/41%/);
    });

    /**
     * Zero outcomes is not zero per cent. The difference between a strategy that
     * failed and one that was never tested is the difference this notebook
     * exists to record.
     */
    it('keeps a never-tested version distinct from one that held nothing', () => {
        render(
            <StrategyTimeline
                strategies={[strategy({ version: 1 })]}
                experiments={[
                    experiment({
                        version: 1,
                        totals: { completed: 0, failed: 0, skipped: 0 },
                    }),
                ]}
            />,
        );

        const evidence = screen.getByTestId('experiment-evidence-1');

        expect(evidence).toHaveTextContent(/not yet tested/i);
        expect(evidence).not.toHaveTextContent(/0%/);
    });

    /**
     * The user's stated reasons are what the next experiment gets written from.
     * The fixture arrives untidy on purpose — a tidy one would pass against an
     * implementation that trims or re-cases, and would prove nothing.
     */
    it('renders a failure reason exactly as it was written', () => {
        const reason = '  kept   FORGETTING by evening.\n\n  then gave up.  ';

        render(
            <StrategyTimeline
                strategies={[strategy({ version: 1 })]}
                experiments={[
                    experiment({
                        version: 1,
                        totals: { completed: 0, failed: 1, skipped: 0 },
                        outcomes: [
                            {
                                outcome: 'failed',
                                reason,
                                logged_at: '2026-08-20T09:00:00+00:00',
                            },
                        ],
                    }),
                ]}
            />,
        );

        expect(screen.getByTestId('experiment-reason-1-0')).toHaveTextContent(
            reason,
            { normalizeWhitespace: false },
        );
    });

    /**
     * Logs attribute through actions.strategy_id, so a v1 failure must stay on
     * v1 even while v2 is the active version.
     */
    it('attributes outcomes to the version that was running', () => {
        render(
            <StrategyTimeline
                strategies={[
                    strategy({ id: 1, version: 1, status: 'superseded' }),
                    strategy({ id: 2, version: 2, status: 'active' }),
                ]}
                experiments={[
                    experiment({
                        version: 1,
                        totals: { completed: 2, failed: 8, skipped: 0 },
                    }),
                    experiment({
                        version: 2,
                        totals: { completed: 9, failed: 1, skipped: 0 },
                    }),
                ]}
            />,
        );

        expect(screen.getByTestId('experiment-evidence-1')).toHaveTextContent(
            /2 of 10 held/i,
        );
        expect(screen.getByTestId('experiment-evidence-2')).toHaveTextContent(
            /9 of 10 held/i,
        );
    });

    it('falls back to the plain outcome count when no experiments are supplied', () => {
        render(
            <StrategyTimeline
                strategies={[strategy({ outcomes_recorded: 11 })]}
            />,
        );

        expect(screen.getByText(/11 outcomes/i)).toBeInTheDocument();
    });
});
