import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { ActiveStrategySummary, IntentionData } from '@/patyourself/types';

const { routerGetMock } = vi.hoisted(() => ({ routerGetMock: vi.fn() }));

const page = { url: '/loops', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        Head: () => null,
        usePage: () => page,
        router: { ...actual.router, get: routerGetMock },
    };
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

const noFilters = { status: null, q: null };

describe('LoopsIndex', () => {
    beforeEach(() => {
        routerGetMock.mockClear();
    });

    it('shows how far into its run each experiment is', () => {
        render(
            <LoopsIndex
                intentions={[intention({ strategy: strategy() })]}
                filters={noFilters}
            />,
        );

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
                filters={noFilters}
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
                filters={noFilters}
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
        render(
            <LoopsIndex
                intentions={[intention({ strategy: null })]}
                filters={noFilters}
            />,
        );

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
        render(
            <LoopsIndex
                intentions={[intention({ strategy: strategy() })]}
                filters={noFilters}
            />,
        );

        const link = screen.getByRole('link', { name: /catch up/i });

        expect(link).toBeInTheDocument();
        expect(link).not.toHaveTextContent(/\d/);
    });

    describe('filter bar', () => {
        it('preserves the active search term when a status chip is tapped', () => {
            render(
                <LoopsIndex
                    intentions={[intention()]}
                    filters={{ status: null, q: 'kettle' }}
                />,
            );

            const chip = screen.getByRole('link', { name: 'paused' });

            expect(chip).toHaveAttribute('href', '/loops?status=paused&q=kettle');
        });

        it('preserves the current status filter when searching', () => {
            render(
                <LoopsIndex
                    intentions={[intention()]}
                    filters={{ status: 'paused', q: null }}
                />,
            );

            fireEvent.change(screen.getByLabelText('Search loops'), {
                target: { value: 'kettle' },
            });
            fireEvent.submit(screen.getByLabelText('Search loops').closest('form')!);

            expect(routerGetMock).toHaveBeenCalledWith(
                '/loops',
                { status: 'paused', q: 'kettle' },
                expect.objectContaining({ preserveState: true, preserveScroll: true }),
            );
        });

        it('marks the current status chip as active and leaves the others inactive', () => {
            render(
                <LoopsIndex
                    intentions={[intention()]}
                    filters={{ status: 'paused', q: null }}
                />,
            );

            expect(screen.getByRole('link', { name: 'paused' }).className).toContain(
                'bg-accent',
            );
            expect(screen.getByRole('link', { name: 'active' }).className).not.toContain(
                'bg-accent',
            );
            expect(screen.getByRole('link', { name: 'All' }).className).not.toContain(
                'bg-accent',
            );
        });

        it('marks "All" active when no status filter is set', () => {
            render(
                <LoopsIndex intentions={[intention()]} filters={noFilters} />,
            );

            expect(screen.getByRole('link', { name: 'All' }).className).toContain(
                'bg-accent',
            );
        });
    });

    describe('empty vs filtered-empty states', () => {
        /**
         * A brand-new user with zero loops and a user whose filters happen to
         * match nothing are told materially different things: onboarding copy
         * for the former, "clear your filters" for the latter. Showing either
         * to the wrong audience is wrong.
         */
        it('shows onboarding copy for a genuinely empty account', () => {
            render(<LoopsIndex intentions={[]} filters={noFilters} />);

            expect(screen.getByText('No loops yet')).toBeInTheDocument();
            expect(
                screen.queryByText('No loops match that.'),
            ).not.toBeInTheDocument();
        });

        it('shows "no matches" copy when a status filter excludes everything', () => {
            render(
                <LoopsIndex
                    intentions={[]}
                    filters={{ status: 'archived', q: null }}
                />,
            );

            expect(screen.getByText('No loops match that.')).toBeInTheDocument();
            expect(screen.queryByText('No loops yet')).not.toBeInTheDocument();
        });

        it('shows "no matches" copy when a search excludes everything', () => {
            render(
                <LoopsIndex
                    intentions={[]}
                    filters={{ status: null, q: 'nothing matches this' }}
                />,
            );

            expect(screen.getByText('No loops match that.')).toBeInTheDocument();
            expect(screen.queryByText('No loops yet')).not.toBeInTheDocument();
        });
    });
});
