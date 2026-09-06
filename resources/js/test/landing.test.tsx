import type * as InertiaReact from '@inertiajs/react';
import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Landing from '@/pages/landing';

// `Head` needs Inertia's head-manager context, which isn't mounted in a bare
// component render. Stub it (keeping the real `Link` so href assertions below
// stay meaningful) so the page renders standalone.
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null };
});

// Three is imported dynamically and lands in its own chunk. jsdom has no
// WebGL, so the scene never boots — which is the point: the page must render
// its content either way, exactly as it does for a visitor whose browser
// refuses the context.
describe('Landing', () => {
    /**
     * The app went zero-LLM: Claude does the thinking through the connector and
     * patyourself keeps the record. The pitch says notebook, not coach — and
     * "evidence, not willpower" because a failure here is about the strategy.
     */
    it('leads with the "evidence, not willpower" headline', () => {
        render(<Landing />);

        expect(screen.getByText('Evidence,')).toBeInTheDocument();
        expect(screen.getByText(/not willpower\./i)).toBeInTheDocument();
        expect(
            screen.getByText(/A lab notebook, not a tracker/i),
        ).toBeInTheDocument();
        expect(screen.queryByText(/coach/i)).not.toBeInTheDocument();
    });

    it('explains all four loop stages', () => {
        render(<Landing />);

        expect(screen.getByText(/Four small steps/i)).toBeInTheDocument();

        for (const stage of ['Cue', 'Craving', 'Response', 'Reward']) {
            expect(screen.getByText(stage)).toBeInTheDocument();
        }
    });

    it('shows the ripple counter starting at zero with its nudge', () => {
        render(<Landing />);

        expect(screen.getByText(/ripples · 000/)).toBeInTheDocument();
        expect(screen.getByText(/tap the field/i)).toBeInTheDocument();
    });

    it('offers a scroll cue to the how-it-works section', () => {
        render(<Landing />);

        expect(
            screen.getByRole('link', { name: /how it works/i }),
        ).toBeInTheDocument();
        expect(document.getElementById('how')).not.toBeNull();
    });

    it('routes the primary CTAs to registration', () => {
        render(<Landing />);

        const register = screen
            .getAllByRole('link')
            .filter((a) => a.getAttribute('href') === '/register');
        // "Create account", hero "Get started", "Start your first loop"
        expect(register.length).toBeGreaterThanOrEqual(3);
    });

    it('offers a login path for returning users', () => {
        render(<Landing />);

        const login = screen
            .getAllByRole('link')
            .filter((a) => a.getAttribute('href') === '/login');
        expect(login.length).toBeGreaterThanOrEqual(1);
    });

    it('renders a canvas for the ripple field', () => {
        const { container } = render(<Landing />);

        expect(container.querySelector('canvas.hero__canvas')).not.toBeNull();
    });

    /**
     * The hero used to fetch Three from unpkg by injecting a <script> at
     * runtime — the one place this app executed code from a host outside its
     * own deploy, pinned by nothing in this repository. Three is now a bundled
     * dependency, and this is what stops the CDN creeping back: restore any
     * loader that appends a script tag and this goes red.
     */
    it('fetches no script from outside the deploy', async () => {
        // jsdom's document persists across tests in this file, so clear first
        // and judge only what this render adds.
        document.head
            .querySelectorAll('script[src]')
            .forEach((script) => script.remove());

        render(<Landing />);

        // The boot is async, so give the effect's promise chain a turn before
        // deciding nothing was injected.
        await act(async () => {
            await Promise.resolve();
        });

        const external = Array.from(
            document.querySelectorAll('script[src]'),
        ).filter((script) => !script.getAttribute('src')!.startsWith('/'));

        expect(external.map((s) => s.getAttribute('src'))).toEqual([]);
    });
});
