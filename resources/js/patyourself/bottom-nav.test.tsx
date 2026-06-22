import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// usePage needs Inertia's context, which isn't mounted in a bare render. Stub it
// with a mutable page object (keeping the real Link so hrefs stay meaningful).
const page = { url: '/dashboard', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, usePage: () => page };
});

import { BottomNav } from './bottom-nav';

describe('BottomNav', () => {
    it('renders the Inbox tab', () => {
        page.props.unread_notifications_count = 0;
        render(<BottomNav />);

        expect(screen.getByText('Inbox')).toBeInTheDocument();
    });

    it('shows the unread badge when there are unread cues', () => {
        page.props.unread_notifications_count = 3;
        render(<BottomNav />);

        expect(screen.getByTestId('inbox-badge')).toHaveTextContent('3');
    });

    it('hides the badge when there are no unread cues', () => {
        page.props.unread_notifications_count = 0;
        render(<BottomNav />);

        expect(screen.queryByTestId('inbox-badge')).not.toBeInTheDocument();
    });

    it('caps the badge at "9+" when the unread count exceeds 9', () => {
        page.props.unread_notifications_count = 15;
        render(<BottomNav />);

        expect(screen.getByTestId('inbox-badge')).toHaveTextContent('9+');
    });

    it('renders the Progress tab', () => {
        page.url = '/dashboard';
        render(<BottomNav />);

        expect(screen.getByText('Progress')).toBeInTheDocument();
    });

    it('marks the Progress tab active on a progress detail route', () => {
        page.url = '/progress/7';
        render(<BottomNav />);

        expect(screen.getByText('Progress').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        page.url = '/dashboard';
    });
});
