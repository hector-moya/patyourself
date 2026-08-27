import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type {
    ActiveStrategySummary,
    IntentionData,
} from '@/patyourself/types';

const page = { url: '/loops', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import LoopsIndex from './index';

function strategy(
    overrides: Partial<ActiveStrategySummary> = {},
): ActiveStrategySummary {
    return {
        intervention_point: 'craving',
        approach: 'Put the bowl away before sitting down',
        rationale: null,
        version: 2,
        day_of_experiment: 9,
        planned_days: 14,
        is_under_review: false,
        ...overrides,
    };
}

function intention(overrides: Partial<IntentionData> = {}): IntentionData {
    return {
        id: 1,
        title: 'Evening snacking',
        type: 'break',
        status: 'active',
        cue: 'Walking past the kitchen',
        craving: 'Something to chew on',
        response: 'Open the cupboard',
        reward: 'Ten quiet minutes',
        description: null,
        metadata: null,
        created_at: null,
        updated_at: null,
        strategy: null,
        active_action: null,
        ...overrides,
    };
}

describe('LoopsIndex', () => {
    it('shows how far into its run each experiment is', () => {
        render(<LoopsIndex intentions={[intention({ strategy: strategy() })]} />);

        expect(screen.getByTestId('loop-experiment-1')).toHaveTextContent(
            /v2 · day 9 of 14/i,
        );
    });

    /**
     * Past its review date the day count stops being the useful fact — the row
     * should say what is wanted, not report an overrun.
     */
    it('asks for a verdict when a version is under review', () => {
        render(
            <LoopsIndex
                intentions={[
                    intention({
                        strategy: strategy({
                            day_of_experiment: 15,
                            planned_days: 14,
                            is_under_review: true,
                        }),
                    }),
                ]}
            />,
        );

        const row = screen.getByTestId('loop-experiment-1');

        expect(row).toHaveTextContent(/ready for a verdict/i);
        expect(row).not.toHaveTextContent(/day 15 of 14/i);
    });

    it('never renders an open-ended run as a countdown', () => {
        render(
            <LoopsIndex
                intentions={[
                    intention({ strategy: strategy({ planned_days: null }) }),
                ]}
            />,
        );

        const row = screen.getByTestId('loop-experiment-1');

        expect(row).toHaveTextContent(/open-ended/i);
        expect(row).not.toHaveTextContent(/of \d/);
    });

    /**
     * A loop with no experiment under test is a success, not neglect. The row
     * says logging continues and offers no prompt to start one.
     */
    it('reads a loop with no experiment as a good state', () => {
        render(<LoopsIndex intentions={[intention({ strategy: null })]} />);

        const row = screen.getByTestId('loop-experiment-1');

        expect(row).toHaveTextContent(/no experiment · logging/i);
        expect(
            screen.queryByText(/start an experiment|overdue|needs/i),
        ).not.toBeInTheDocument();
    });

    /**
     * An unlogged occasion never expires, so the catch-up link carries no count.
     * A number there would turn the record into a scoreboard.
     */
    it('links to catch-up without counting anything back', () => {
        render(<LoopsIndex intentions={[intention({ strategy: strategy() })]} />);

        const link = screen.getByRole('link', { name: /catch up/i });

        expect(link).toBeInTheDocument();
        expect(link).not.toHaveTextContent(/\d/);
    });
});
