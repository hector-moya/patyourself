import type * as InertiaReact from '@inertiajs/react';
import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import Register from '@/pages/auth/register';

/**
 * `Head` needs Inertia's head-manager context and `Form` needs its router, and
 * neither is mounted in a bare component render. `Form` is stubbed down to the
 * render-prop contract the page actually uses, so the fields underneath still
 * render and can be asserted on.
 */
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        Head: () => null,
        Form: ({
            children,
        }: {
            children: (bag: {
                processing: boolean;
                errors: Record<string, string>;
            }) => ReactNode;
        }) => <form>{children({ processing: false, errors: {} })}</form>,
    };
});

describe('Register', () => {
    /**
     * Every schedule in the app is worked out from the account's zone, and this
     * hidden field is the only place a new account supplies one. It is read
     * from the browser at render rather than filled in by an effect — see the
     * page's own note on why the server must not answer this question.
     */
    it('carries the browser time zone in the hidden field', () => {
        const { container } = render(<Register passwordRules="" />);
        const field = container.querySelector(
            'input[name="timezone"]',
        ) as HTMLInputElement;

        expect(field).not.toBeNull();
        expect(field.value).toBe(
            Intl.DateTimeFormat().resolvedOptions().timeZone,
        );
        expect(field.value).not.toBe('');
    });

    /**
     * The field has to arrive already filled. A value that only appears after
     * a second render is a value a fast submit can miss, which is what the
     * effect this replaced could do.
     */
    it('has the zone on the very first render', () => {
        const seen: string[] = [];

        const { container } = render(<Register passwordRules="" />);

        seen.push(
            (
                container.querySelector(
                    'input[name="timezone"]',
                ) as HTMLInputElement
            ).value,
        );

        expect(seen[0]).toBe(Intl.DateTimeFormat().resolvedOptions().timeZone);
    });
});
