import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// usePage needs Inertia's context, which isn't mounted in a bare render. Stub it
// with a mutable page object (keeping the real Link so hrefs stay meaningful).
const page = { url: '/dashboard', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, usePage: () => page };
});

import { BottomNav } from './bottom-nav';

describe('BottomNav', () => {
    afterEach(() => {
        page.url = '/dashboard';
        page.props.unread_notifications_count = 0;
    });

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
        expect(screen.getByText('Progress').closest('a')).toHaveAttribute(
            'href',
            '/progress',
        );
    });

    it('marks the Progress tab active on a progress detail route', () => {
        page.url = '/progress/7';
        render(<BottomNav />);

        expect(screen.getByText('Progress').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    /**
     * Blob was reachable only from the small corner on Today, which renders
     * nothing at all until the first outcome — so before that there was no door
     * to it anywhere on a phone. The tab is that door.
     */
    it('renders the Companion tab', () => {
        page.url = '/dashboard';
        render(<BottomNav />);

        expect(screen.getByText('Companion').closest('a')).toHaveAttribute(
            'href',
            '/companion',
        );
    });

    it('marks the Companion tab active on its own screen', () => {
        page.url = '/companion';
        render(<BottomNav />);

        expect(screen.getByText('Companion').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    /**
     * The rail keeps Settings in a footer below a rule; a tab bar has no
     * footer, and leaving it out left settings unreachable on a phone
     * altogether — there was no door to it anywhere below `lg`.
     */
    it('carries Settings as the last tab', () => {
        page.url = '/dashboard';
        render(<BottomNav />);

        const tabs = screen.getAllByRole('link');

        expect(tabs.at(-1)).toHaveTextContent('Settings');
        expect(tabs.at(-1)).toHaveAttribute('href', '/settings/profile');
    });

    /** Every settings screen is under /settings, not just the profile page. */
    it('marks Settings active anywhere in the settings section', () => {
        page.url = '/settings/notifications';
        render(<BottomNav />);

        expect(screen.getByText('Settings').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
    });

    it('leaves Settings inactive elsewhere', () => {
        page.url = '/dashboard';
        render(<BottomNav />);

        expect(screen.getByText('Settings').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });
});
