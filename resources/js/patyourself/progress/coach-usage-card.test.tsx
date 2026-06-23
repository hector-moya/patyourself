import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { CoachUsageSnapshot } from '@/patyourself/types';
import { CoachUsageCard } from './coach-usage-card';

function usage(
    overrides: Partial<CoachUsageSnapshot> = {},
): CoachUsageSnapshot {
    return {
        used: 1500,
        budget: 200000,
        remaining: 198500,
        breakdown: { summarizer: 900, strategist: 300, coach: 300 },
        ...overrides,
    };
}

describe('CoachUsageCard', () => {
    it('shows used over budget and the remaining tokens', () => {
        render(<CoachUsageCard usage={usage()} />);

        expect(screen.getByText('Coach usage today')).toBeInTheDocument();
        expect(screen.getByText('1,500 / 200,000')).toBeInTheDocument();
        expect(
            screen.getByText(/198,500 tokens remaining/),
        ).toBeInTheDocument();
        expect(screen.getByTestId('usage-bar')).toBeInTheDocument();
    });

    it('groups the breakdown into auto-coaching vs chat', () => {
        render(<CoachUsageCard usage={usage()} />);

        // auto-coaching = summarizer (900) + strategist (300) = 1,200; chat = coach (300)
        expect(screen.getByText(/Auto-coaching 1,200/)).toBeInTheDocument();
        expect(screen.getByText(/Chat 300/)).toBeInTheDocument();
    });

    it('shows "No cap" and no bar when the budget is uncapped', () => {
        render(
            <CoachUsageCard usage={usage({ budget: 0, remaining: null })} />,
        );

        expect(screen.getByText(/no cap/i)).toBeInTheDocument();
        expect(screen.queryByTestId('usage-bar')).not.toBeInTheDocument();
    });

    it('flags an over-budget account', () => {
        render(
            <CoachUsageCard usage={usage({ used: 200000, remaining: 0 })} />,
        );

        expect(screen.getByText(/over budget/i)).toBeInTheDocument();
    });
});
