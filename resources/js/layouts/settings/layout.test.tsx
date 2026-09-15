import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const page = {
    url: '/settings/profile',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import SettingsLayout from './layout';

afterEach(() => {
    page.url = '/settings/profile';
});

describe('SettingsLayout', () => {
    /**
     * The defect this pins: settings resolved to `[AppLayout, SettingsLayout]`,
     * and AppLayout is the Laravel starter kit's shell. Walking into settings
     * replaced the whole app — the five screens vanished and were replaced by
     * Dashboard, Repository and Documentation, with no way back except the
     * browser's own.
     */
    it('keeps the app inside settings', () => {
        render(
            <SettingsLayout>
                <p>Profile form</p>
            </SettingsLayout>,
        );

        for (const label of [
            'Today',
            'Loops',
            'Companion',
            'Progress',
            'Inbox',
        ]) {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        }
    });

    it('shows none of the starter kit it was generated from', () => {
        render(
            <SettingsLayout>
                <p>Profile form</p>
            </SettingsLayout>,
        );

        expect(screen.queryByText('Repository')).toBeNull();
        expect(screen.queryByText('Documentation')).toBeNull();
        expect(screen.queryByText('Dashboard')).toBeNull();
        expect(screen.queryByText(/laravel starter kit/i)).toBeNull();
    });

    it('renders the settings sections and the page inside them', () => {
        render(
            <SettingsLayout>
                <p>Profile form</p>
            </SettingsLayout>,
        );

        const sections = screen.getByRole('navigation', { name: 'Settings' });

        for (const section of [
            'Profile',
            'Security',
            'Notifications',
            'Timezone',
            'Appearance',
            'Your record',
        ]) {
            expect(sections).toHaveTextContent(section);
        }

        expect(screen.getByText('Profile form')).toBeInTheDocument();
    });

    it('marks the section being viewed', () => {
        page.url = '/settings/notifications';

        render(
            <SettingsLayout>
                <p>Notification form</p>
            </SettingsLayout>,
        );

        const sections = screen.getByRole('navigation', { name: 'Settings' });
        const current = sections.querySelector('[aria-current="page"]');

        expect(current).toHaveTextContent('Notifications');
    });
});
