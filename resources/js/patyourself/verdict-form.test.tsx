import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let mockErrors: Record<string, string> = {};

/**
 * `Form` is stood in for so the render-prop's `errors` can be handed in
 * directly, the way the server's own validation errors reach it, without a
 * live request behind it — the same seam `gym-record.test.tsx` and
 * `set-grid.test.tsx` stub.
 */
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        Form: (props: {
            action?: string;
            method?: string;
            options?: unknown;
            children:
                | ((props: {
                      processing: boolean;
                      errors: Record<string, string>;
                  }) => React.ReactNode)
                | React.ReactNode;
            [key: string]: unknown;
        }) => {
            const { action, method, children } = props;

            // Everything else rides through to the DOM, minus Inertia's own
            // props — `options` is not a form attribute and React warns if it
            // reaches one.
            const rest = Object.fromEntries(
                Object.entries(props).filter(
                    ([key]) =>
                        !['action', 'method', 'children', 'options'].includes(
                            key,
                        ),
                ),
            );

            return (
                <form action={action} method={method} {...rest}>
                    {typeof children === 'function'
                        ? children({ processing: false, errors: mockErrors })
                        : children}
                </form>
            );
        },
    };
});

import VerdictForm from './verdict-form';

describe('VerdictForm', () => {
    beforeEach(() => {
        mockErrors = {};
    });

    it('posts to the action it is given', () => {
        const { container } = render(
            <VerdictForm action="/occurrences/42/logs" />,
        );

        expect(container.querySelector('form')?.getAttribute('action')).toBe(
            '/occurrences/42/logs',
        );
    });

    it('shows no reason field until "Did not hold" is chosen', () => {
        render(<VerdictForm action="/occurrences/42/logs" />);

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();
    });

    it('shows the reason field once "Did not hold" is chosen', () => {
        render(<VerdictForm action="/occurrences/42/logs" />);

        fireEvent.click(screen.getByText(/did not hold/i));

        expect(
            screen.getByLabelText(/what happened, in your words/i),
        ).toBeInTheDocument();
    });

    it('hides the reason field again when the outcome changes away from "Did not hold"', () => {
        render(<VerdictForm action="/occurrences/42/logs" />);

        fireEvent.click(screen.getByText(/did not hold/i));
        expect(
            screen.getByLabelText(/what happened, in your words/i),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByText(/did it/i));

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();
    });

    it('shows no submit until an outcome is chosen', () => {
        render(<VerdictForm action="/occurrences/42/logs" />);

        expect(
            screen.queryByRole('button', { name: /log it/i }),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByText(/did it/i));

        expect(
            screen.getByRole('button', { name: /log it/i }),
        ).toBeInTheDocument();
    });

    it("renders the server's reason error when there is one", () => {
        mockErrors = { reason: 'The reason field is required.' };

        render(<VerdictForm action="/occurrences/42/logs" />);

        fireEvent.click(screen.getByText(/did not hold/i));

        expect(
            screen.getByText(/the reason field is required/i),
        ).toBeInTheDocument();
    });

    it('passes its testId through to the form element', () => {
        render(
            <VerdictForm action="/occurrences/42/logs" testId="verdict-form" />,
        );

        expect(screen.getByTestId('verdict-form')).toBeInTheDocument();
    });
});
