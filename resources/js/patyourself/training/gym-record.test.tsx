import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

/**
 * `Form` is stood in for so a click can be resolved synchronously, without a
 * live server behind it. It renders a real `<form>` (so the action/method
 * attributes stay assertable, matching how `dashboard.test.tsx` checks
 * `Form`'s rendered output elsewhere) and, on submit, calls `onSuccess` with a
 * fake page carrying the materialised occurrence id — standing in for the
 * redirect `training.session.materialise` actually performs once it lands on
 * `training.session.show`.
 */
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        Form: ({
            action,
            method,
            onSuccess,
            children,
            ...rest
        }: {
            action?: string;
            method?: string;
            onSuccess?: (page: { props: Record<string, unknown> }) => void;
            children: ((props: { processing: boolean }) => React.ReactNode) | React.ReactNode;
            [key: string]: unknown;
        }) => (
            <form
                action={action}
                method={method}
                onSubmit={(event) => {
                    event.preventDefault();
                    onSuccess?.({ props: { occurrence_id: 42 } });
                }}
                {...rest}
            >
                {typeof children === 'function'
                    ? children({ processing: false })
                    : children}
            </form>
        ),
    };
});

import GymRecord from './gym-record';

describe('GymRecord', () => {
    /**
     * Killing mutation: hardcode the link's href to the action-keyed
     * materialise URL even when an occurrence already exists. The compact
     * line would then point at the wrong kind of route (a POST-only endpoint)
     * instead of the session screen — verified by direct mutation and rerun.
     */
    it('renders one compact line linking to the session page once the occurrence exists', () => {
        render(
            <GymRecord
                occurrenceId={42}
                actionId={7}
                onOccurrenceMaterialised={vi.fn()}
            />,
        );

        const link = screen.getByRole('link');

        expect(link).toHaveAttribute('href', '/occurrences/42/session');
    });

    /**
     * Killing mutation: point the begin-recording form at the occurrence logs
     * route (or any URL other than the action-keyed materialise endpoint).
     * Recording would never begin — verified by direct mutation and rerun.
     */
    it('begins recording against the action-keyed materialise endpoint when no occurrence exists yet', () => {
        render(
            <GymRecord
                occurrenceId={null}
                actionId={7}
                onOccurrenceMaterialised={vi.fn()}
            />,
        );

        expect(screen.getByTestId('gym-record-begin')).toHaveAttribute(
            'action',
            '/actions/7/session',
        );
    });

    /**
     * This is the batch-1 defect staying closed: the occasion this surface
     * materialises must reach the host, or the host's own verdict controls
     * would keep posting to the action route's live slot instead of the
     * occasion recording actually began against.
     *
     * Killing mutation: drop the `onSuccess` handler (or stop reading
     * `occurrence_id` off the resulting page) so recording begins but the
     * host never learns the id — verified by direct mutation and rerun.
     */
    it('calls onOccurrenceMaterialised when recording begins', () => {
        const onOccurrenceMaterialised = vi.fn();

        render(
            <GymRecord
                occurrenceId={null}
                actionId={7}
                onOccurrenceMaterialised={onOccurrenceMaterialised}
            />,
        );

        fireEvent.submit(screen.getByTestId('gym-record-begin'));

        expect(onOccurrenceMaterialised).toHaveBeenCalledWith(42);
    });

    it('never calls onOccurrenceMaterialised before recording begins', () => {
        const onOccurrenceMaterialised = vi.fn();

        render(
            <GymRecord
                occurrenceId={7}
                actionId={7}
                onOccurrenceMaterialised={onOccurrenceMaterialised}
            />,
        );

        expect(onOccurrenceMaterialised).not.toHaveBeenCalled();
    });
});
