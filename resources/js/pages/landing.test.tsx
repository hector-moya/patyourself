import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import Landing from './landing';

// `Head` needs Inertia's head-manager context, which isn't mounted in a bare
// component render. Stub it (keeping the real `Link` so href assertions below
// stay meaningful) so the page renders standalone.
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, Head: () => null };
});

describe('Landing', () => {
    it('leads with the loop headline', () => {
        render(<Landing />);

        expect(screen.getByText(/not just the streak/i)).toBeInTheDocument();
    });

    it('explains all four loop stages', () => {
        render(<Landing />);

        expect(
            screen.getByText(/Every habit runs the same four beats/i),
        ).toBeInTheDocument();
        // Each stage name appears in both the hero chips and the anatomy cards.
        for (const stage of ['Cue', 'Craving', 'Response', 'Reward']) {
            expect(screen.getAllByText(stage).length).toBeGreaterThanOrEqual(1);
        }
    });

    it('shows a sample coach turn with action chips', () => {
        render(<Landing />);

        expect(
            screen.getByText(/Set coffee as my cue/i),
        ).toBeInTheDocument();
    });

    it('routes both primary CTAs to registration', () => {
        render(<Landing />);

        const register = screen
            .getAllByRole('link')
            .filter((a) => a.getAttribute('href') === '/register');
        expect(register.length).toBeGreaterThanOrEqual(2);
    });

    it('offers a login path for returning users', () => {
        render(<Landing />);

        const login = screen
            .getAllByRole('link')
            .filter((a) => a.getAttribute('href') === '/login');
        expect(login.length).toBeGreaterThanOrEqual(1);
    });

    it('frames the product as a coach, not a tracker', () => {
        render(<Landing />);

        expect(
            screen.getByText(/Built like a good therapist/i),
        ).toBeInTheDocument();
    });
});
