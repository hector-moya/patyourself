import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = {
    url: '/settings/notifications',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import Notifications from './notifications';

const modes = ['off', 'digest', 'every_cue'];

describe('Notification settings', () => {
    it('shows the digest time when the digest mode is selected', () => {
        render(
            <Notifications
                emailReminders="digest"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.getByLabelText(/time/i)).toHaveValue('07:00');
    });

    it('hides the digest time for the every-cue mode', () => {
        render(
            <Notifications
                emailReminders="every_cue"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.queryByLabelText(/time/i)).not.toBeInTheDocument();
    });

    it('hides the digest time when reminders are off', () => {
        render(
            <Notifications
                emailReminders="off"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.queryByLabelText(/time/i)).not.toBeInTheDocument();
    });

    it('offers all three modes', () => {
        render(
            <Notifications
                emailReminders="digest"
                digestTime="07:00"
                modes={modes}
            />,
        );

        expect(screen.getAllByRole('radio')).toHaveLength(3);
    });
});
