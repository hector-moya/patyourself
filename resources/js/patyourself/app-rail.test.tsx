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

import { AppRail } from './app-rail';

describe('AppRail', () => {
    afterEach(() => {
        page.url = '/dashboard';
        page.props.unread_notifications_count = 0;
    });

    it('links every primary tab', () => {
        render(<AppRail />);

        expect(screen.getByText('Coach').closest('a')).toHaveAttribute(
            'href',
            '/dashboard',
        );
        expect(screen.getByText('Loops').closest('a')).toHaveAttribute(
            'href',
            '/intentions',
        );
        expect(screen.getByText('Progress').closest('a')).toHaveAttribute(
            'href',
            '/progress',
        );
        expect(screen.getByText('Inbox').closest('a')).toHaveAttribute(
            'href',
            '/inbox',
        );
    });

    it('marks the active tab from the current path, including nested routes', () => {
        page.url = '/progress/7';
        render(<AppRail />);

        expect(screen.getByText('Progress').closest('a')).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(screen.getByText('Coach').closest('a')).not.toHaveAttribute(
            'aria-current',
        );
    });

    it('surfaces the unread count on the Inbox tab', () => {
        page.props.unread_notifications_count = 3;
        render(<AppRail />);

        expect(screen.getByTestId('rail-inbox-badge')).toHaveTextContent('3');
    });

    it('hides the unread badge when there are no unread cues', () => {
        page.props.unread_notifications_count = 0;
        render(<AppRail />);

        expect(
            screen.queryByTestId('rail-inbox-badge'),
        ).not.toBeInTheDocument();
    });

    it('caps the unread badge at "9+"', () => {
        page.props.unread_notifications_count = 42;
        render(<AppRail />);

        expect(screen.getByTestId('rail-inbox-badge')).toHaveTextContent('9+');
    });

    it('offers a settings link for desktop', () => {
        render(<AppRail />);

        expect(screen.getByText('Settings').closest('a')).toHaveAttribute(
            'href',
            '/settings/profile',
        );
    });
});
