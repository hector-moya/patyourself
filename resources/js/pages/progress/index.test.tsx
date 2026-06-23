import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { CoachUsageSnapshot, LoopProgressCard } from '@/patyourself/types';

const page = { url: '/progress', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import ProgressIndex from './index';

function card(overrides: Partial<LoopProgressCard> = {}): LoopProgressCard {
    return {
        id: 3,
        title: 'Morning walk',
        type: 'build',
        streak: { outcome: 'completed', length: 5 },
        completion_rate: 82,
        totals: { completed: 12, failed: 2, skipped: 1 },
        recent: ['completed', 'completed', 'failed', 'completed'],
        last_logged_at: '2026-06-22T07:00:00Z',
        summary_excerpt: 'You complete most mornings.',
        ...overrides,
    };
}

function usage(
    overrides: Partial<CoachUsageSnapshot> = {},
): CoachUsageSnapshot {
    return {
        used: 0,
        budget: 200000,
        remaining: 200000,
        breakdown: {},
        ...overrides,
    };
}

describe('ProgressIndex', () => {
    it('renders a card per active loop with its streak, rate and sparkline', () => {
        render(<ProgressIndex loops={[card()]} usage={usage()} />);

        expect(screen.getByText('Morning walk')).toBeInTheDocument();
        expect(screen.getByText('82%')).toBeInTheDocument();
        expect(screen.getByText(/5 in a row/)).toBeInTheDocument();
        expect(screen.getByTestId('outcome-strip')).toBeInTheDocument();
    });

    it('links a card to its detail screen', () => {
        render(<ProgressIndex loops={[card({ id: 7 })]} usage={usage()} />);

        expect(screen.getByText('Morning walk').closest('a')).toHaveAttribute(
            'href',
            '/progress/7',
        );
    });

    it('shows "No activity yet" and a dash rate for a loop with no logs', () => {
        render(
            <ProgressIndex
                loops={[
                    card({
                        completion_rate: null,
                        recent: [],
                        streak: { outcome: null, length: 0 },
                    }),
                ]}
                usage={usage()}
            />,
        );

        expect(screen.getByText('—')).toBeInTheDocument();
        expect(screen.getByText(/no activity yet/i)).toBeInTheDocument();
    });

    it('shows the empty state with a coach CTA when there are no loops', () => {
        render(<ProgressIndex loops={[]} usage={usage()} />);

        expect(screen.getByText(/no active loops yet/i)).toBeInTheDocument();
        expect(
            screen.getByText(/start a loop with your coach/i).closest('a'),
        ).toHaveAttribute('href', '/dashboard');
    });
});
