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

// The hero is flat. It used to run a Three.js point field behind the copy,
// which meant the page had two appearances — the scene, and the static
// fallback a browser without WebGL got. Only one of those was ever asserted
// here, because jsdom has no WebGL either. Now there is only one appearance,
// and these tests describe it directly rather than describing a degradation.
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

    /**
     * The counter and its nudge went with the point field. They were driven by
     * the scene's own `py-pat` events, so without a field they would have sat
     * at "ripples · 000" telling a visitor to tap something that is no longer
     * there. The mutation this kills is re-adding either one without the
     * interaction that earns it.
     */
    it('offers no ripple counter and nothing to tap', () => {
        render(<Landing />);

        expect(screen.queryByText(/ripples ·/)).not.toBeInTheDocument();
        expect(screen.queryByText(/tap the field/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/one small act/i)).not.toBeInTheDocument();
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

    /**
     * The hero draws no canvas at all now, which is the whole point of the
     * change: a public landing page was shipping a 3D engine — the single
     * largest chunk in the bundle, larger than the app itself — to draw
     * expanding rings behind some copy.
     *
     * This asserts the absence rather than the sparseness, so it reds if a
     * canvas comes back by any route, not only if Three does.
     */
    it('draws no canvas, so the page ships no renderer', () => {
        const { container } = render(<Landing />);

        expect(container.querySelector('canvas')).toBeNull();
    });

    /**
     * The hero used to fetch Three from unpkg by injecting a <script> at
     * runtime — the one place this app executed code from a host outside its
     * own deploy, pinned by nothing in this repository. Three then became a
     * bundled dependency, and is now gone entirely; this is what stops a CDN
     * creeping back in its place. Restore any loader that appends a script tag
     * and this goes red.
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
