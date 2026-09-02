import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = {
    url: '/settings/record',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import Record from './record';

describe('Your record screen', () => {
    it('offers both formats, each pointing at the export endpoint', () => {
        render(<Record />);

        expect(
            screen.getByRole('link', { name: /download as json/i }),
        ).toHaveAttribute('href', '/export');

        expect(
            screen.getByRole('link', { name: /download as markdown/i }),
        ).toHaveAttribute('href', '/export?format=md');
    });

    /**
     * The whole reason this screen exists is that `/export` had no handle.
     * Inertia's `<Link>` would fetch the download and then find no page in the
     * response, so these have to stay plain anchors — a regression to `<Link>`
     * would leave the buttons doing nothing at all, silently.
     *
     * Asserted on behaviour, not on the tag: `<Link>` renders an `<a>` too, so
     * checking `tagName` or the absence of some marker attribute would pass
     * either way. What actually separates them is that `<Link>` cancels the
     * click and takes over. The listener sits on `document` so it runs after
     * React's root handler, and cancels the event itself so jsdom does not
     * warn about navigating.
     */
    it('lets the browser handle the click, rather than Inertia', () => {
        render(<Record />);

        for (const name of [/download as json/i, /download as markdown/i]) {
            let preventedByTheApp: boolean | null = null;

            const record = (event: Event) => {
                preventedByTheApp = event.defaultPrevented;
                event.preventDefault();
            };

            document.addEventListener('click', record);
            screen
                .getByRole('link', { name })
                .dispatchEvent(
                    new MouseEvent('click', {
                        bubbles: true,
                        cancelable: true,
                    }),
                );
            document.removeEventListener('click', record);

            expect(preventedByTheApp).toBe(false);
        }
    });

    /**
     * Read-only by design — see ExportController. Nothing here may imply a way
     * back in, because there is not one.
     */
    it('does not offer an import', () => {
        render(<Record />);

        expect(screen.queryByText(/import|upload|restore/i)).toBeNull();
    });
});
