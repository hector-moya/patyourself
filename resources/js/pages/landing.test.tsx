import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Landing from './landing';

// `Head` needs Inertia's head-manager context, which isn't mounted in a bare
// component render. Stub it (keeping the real `Link` so href assertions below
// stay meaningful) so the page renders standalone.
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null };
});

// Three.js loads from CDN at runtime; in jsdom the script never resolves, so
// the ripple scene simply doesn't boot. The page must still render its content.
describe('Landing', () => {
    it('leads with the "progress, not perfection" headline', () => {
        render(<Landing />);

        expect(screen.getByText('Progress,')).toBeInTheDocument();
        expect(screen.getByText(/not perfection\./i)).toBeInTheDocument();
        expect(screen.getByText(/A coach, not a tracker/i)).toBeInTheDocument();
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
});
