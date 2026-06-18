import * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { NotificationData } from '@/patyourself/types';

// CoachLayout's <Head> and BottomNav's usePage need Inertia context; stub them.
const page = { url: '/inbox', props: { unread_notifications_count: 1 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import Inbox from './inbox';

function notif(overrides: Partial<NotificationData> = {}): NotificationData {
    return {
        id: 'n1',
        action_id: 5,
        intention_id: 9,
        title: 'Meditate',
        fired_at: '2026-06-15T07:00:00Z',
        read_at: null,
        ...overrides,
    };
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('Inbox', () => {
    it('renders an unread cue with its title and an unread marker', () => {
        render(<Inbox notifications={[notif()]} />);

        expect(screen.getByText(/Meditate/)).toBeInTheDocument();
        expect(screen.getByTestId('unread-dot')).toBeInTheDocument();
    });

    it('renders a read cue without an unread marker', () => {
        render(
            <Inbox
                notifications={[notif({ read_at: '2026-06-15T08:00:00Z' })]}
            />,
        );

        expect(screen.getByText(/Meditate/)).toBeInTheDocument();
        expect(screen.queryByTestId('unread-dot')).not.toBeInTheDocument();
    });

    it('shows an empty state when there are no cues', () => {
        render(<Inbox notifications={[]} />);

        expect(screen.getByText(/no cues yet/i)).toBeInTheDocument();
    });

    it('marks all read via the inbox endpoint', async () => {
        const patch = vi
            .spyOn(InertiaReact.router, 'patch')
            .mockImplementation(() => {});
        render(<Inbox notifications={[notif()]} />);

        await userEvent.click(screen.getByText(/mark all read/i));

        expect(patch.mock.calls[0][0]).toBe('/inbox/read-all');
    });

    it('marks an unread cue read when it is tapped', async () => {
        vi.spyOn(InertiaReact.router, 'visit').mockImplementation(() => {});
        const patch = vi
            .spyOn(InertiaReact.router, 'patch')
            .mockImplementation(() => {});
        render(<Inbox notifications={[notif()]} />);

        await userEvent.click(screen.getByText(/Meditate/));

        expect(patch).toHaveBeenCalledWith(
            '/inbox/n1/read',
            {},
            { preserveScroll: true, preserveState: true },
        );
    });

    it('does not fire a read request when a read cue is tapped', async () => {
        vi.spyOn(InertiaReact.router, 'visit').mockImplementation(() => {});
        const patch = vi
            .spyOn(InertiaReact.router, 'patch')
            .mockImplementation(() => {});
        render(
            <Inbox
                notifications={[notif({ read_at: '2026-06-15T08:00:00Z' })]}
            />,
        );

        await userEvent.click(screen.getByText(/Meditate/));

        expect(patch).not.toHaveBeenCalled();
    });
});
