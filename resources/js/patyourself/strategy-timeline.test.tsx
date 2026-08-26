import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { StrategyData } from '@/patyourself/types';
import { StrategyTimeline } from './strategy-timeline';

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
});
