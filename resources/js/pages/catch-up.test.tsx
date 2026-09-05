import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import type { PendingOccurrenceData } from '@/patyourself/types';

const page = { url: '/catch-up', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import CatchUp from './catch-up';

function occurrence(
    overrides: Partial<PendingOccurrenceData> = {},
): PendingOccurrenceData {
    return {
        id: 1,
        loop_id: 1,
        loop_title: 'Eating to 80%',
        workflow: null,
        action_id: 1,
        action_title: 'Dinner',
        scheduled_for: '2026-08-24T19:00:00+00:00',
        ...overrides,
    };
}

describe('CatchUp', () => {
    it('groups occasions under the loop they belong to', () => {
        render(
            <CatchUp
                occurrences={[
                    occurrence({ id: 1, action_title: 'Lunch' }),
                    occurrence({ id: 2, action_title: 'Dinner' }),
                    occurrence({
                        id: 3,
                        loop_id: 2,
                        loop_title: 'Walking daily',
                        action_title: 'Morning walk',
                    }),
                ]}
                showing_all={false}
            />,
        );

        expect(screen.getByText('Eating to 80%')).toBeInTheDocument();
        expect(screen.getByText('Walking daily')).toBeInTheDocument();
        expect(screen.getByText('Lunch')).toBeInTheDocument();
        expect(screen.getByText('Morning walk')).toBeInTheDocument();
    });

    it('dates each occasion by the day it happened', () => {
        render(<CatchUp occurrences={[occurrence()]} showing_all={false} />);

        expect(screen.getByText(/24 Aug/i)).toBeInTheDocument();
    });

    it('asks for the reason only once a failure is chosen', async () => {
        render(<CatchUp occurrences={[occurrence()]} showing_all={false} />);

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();

        await userEvent.click(screen.getByText(/did not hold/i));

        expect(
            screen.getByLabelText(/what happened, in your words/i),
        ).toBeInTheDocument();
    });

    it('offers a skip as its own outcome, not a softer failure', () => {
        render(<CatchUp occurrences={[occurrence()]} showing_all={false} />);

        expect(screen.getByText(/never happened/i)).toBeInTheDocument();
    });

    it('never counts the backlog back at the user', () => {
        render(
            <CatchUp
                occurrences={[
                    occurrence({ id: 1 }),
                    occurrence({ id: 2 }),
                    occurrence({ id: 3 }),
                ]}
                showing_all={false}
            />,
        );

        // No "3 waiting", no overdue framing. A backlog is not debt.
        expect(screen.queryByText(/overdue|missed|behind/i)).toBeNull();
        expect(screen.queryByText(/\b3\b/)).toBeNull();
    });

    it('states the empty case plainly, without congratulating', () => {
        render(<CatchUp occurrences={[]} showing_all={false} />);

        expect(
            screen.getByText(/nothing waiting to be logged/i),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/well done|great work|all caught up!/i),
        ).toBeNull();
    });

    it('offers the whole backlog behind an explicit control', () => {
        const { rerender } = render(
            <CatchUp occurrences={[occurrence()]} showing_all={false} />,
        );

        expect(
            screen.getByRole('link', { name: /show everything further back/i }),
        ).toHaveAttribute('href', '/catch-up?since=all');

        rerender(<CatchUp occurrences={[occurrence()]} showing_all={true} />);

        expect(
            screen.queryByRole('link', {
                name: /show everything further back/i,
            }),
        ).not.toBeInTheDocument();
    });
});
