import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const page = { url: '/companion', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import {
    companion,
    noCompanion,
    unlock,
} from '@/patyourself/companion.fixture';

import CompanionPage from './companion';

describe('Companion screen', () => {
    /**
     * Nothing yet is stated as a fact about the record, with nothing owed and
     * nothing to act on — and no empty room either, since there is nobody to
     * put in it.
     */
    it('says what brings Blob out, without asking for it', () => {
        render(<CompanionPage companion={noCompanion()} />);

        expect(screen.getByText(/blob turns up once/i)).toBeInTheDocument();
        expect(screen.queryByText(/locked|to unlock|remaining/i)).toBeNull();
        expect(screen.queryByRole('button', { name: /pet/i })).toBeNull();
    });

    it('lists what has happened, newest first, with the date each arrived', () => {
        render(
            <CompanionPage
                companion={companion({
                    stage_index: 3,
                    log_count: 5,
                    features: ['blob', 'legs'],
                    items: [{ type: 'shoes', variant: null }],
                    unlocks: [
                        unlock(),
                        unlock({
                            name: 'legs',
                            message: 'Blob has legs now.',
                            unlocked_at: '2026-08-22T09:00:00+00:00',
                        }),
                        unlock({
                            kind: 'item',
                            name: 'shoes',
                            message: 'Blob has shoes now.',
                            unlocked_at: '2026-08-25T09:00:00+00:00',
                        }),
                    ],
                    latest_unlock: unlock({ kind: 'item', name: 'shoes' }),
                })}
            />,
        );

        const rows = screen.getAllByRole('listitem');

        expect(rows[0]).toHaveTextContent('shoes');
        expect(rows[0]).toHaveTextContent('25 Aug 2026');
        expect(rows[2]).toHaveTextContent('blob');
        expect(screen.getByText('Blob has shoes now.')).toBeInTheDocument();
    });

    /**
     * The acceptance criterion for this screen. History, not a trophy case: no
     * locked slot, no remaining count, no preview of what comes next, and
     * nothing anywhere that reads as a score.
     */
    it('never shows what has not happened', () => {
        render(<CompanionPage companion={companion()} />);

        expect(
            screen.queryByText(
                /locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i,
            ),
        ).toBeNull();
    });

    it('relays what Blob has to say, near Blob', () => {
        render(
            <CompanionPage
                companion={companion()}
                remark="Blob has been standing by the window a lot this week."
            />,
        );

        expect(
            screen.getByText(/standing by the window a lot this week/i),
        ).toBeInTheDocument();
    });

    /**
     * Silence, not a placeholder and not a default line. Nothing on the screen
     * should suggest a remark is missing.
     */
    it('says nothing when there is nothing to say', () => {
        render(<CompanionPage companion={companion()} remark={null} />);

        expect(
            screen.queryByTestId('companion-remark'),
        ).toBeNull();
        expect(screen.queryByText(/nothing to say|no remarks/i)).toBeNull();
    });

    it('names a recoloured item by its variant', () => {
        render(
            <CompanionPage
                companion={companion({
                    items: [{ type: 'scarf', variant: 'coral' }],
                    unlocks: [
                        unlock({
                            kind: 'item',
                            name: 'scarf',
                            variant: 'coral',
                            message: 'Blob has another scarf, in coral.',
                        }),
                    ],
                })}
            />,
        );

        expect(screen.getByText('scarf (coral)')).toBeInTheDocument();
    });

    describe('pet and play', () => {
        /**
         * Always enabled. No cooldown, no daily limit, no counter and no meter
         * — pressing them is not progress and Blob never asks to be pressed.
         */
        it('offers both, with nothing gating them', () => {
            render(<CompanionPage companion={companion()} />);

            const pet = screen.getByRole('button', { name: /pet/i });
            const play = screen.getByRole('button', { name: /play/i });

            expect(pet).toBeEnabled();
            expect(play).toBeEnabled();

            fireEvent.click(pet);
            fireEvent.click(pet);
            fireEvent.click(play);

            expect(pet).toBeEnabled();
            expect(play).toBeEnabled();
        });

        it('plays the reaction on the drawing', () => {
            const { container } = render(
                <CompanionPage companion={companion()} />,
            );

            fireEvent.click(screen.getByRole('button', { name: /pet/i }));

            expect(
                container
                    .querySelector('.blob-anim')
                    ?.getAttribute('data-animation'),
            ).toBe('pet');
        });

        /** Nothing about a press is recorded, so nothing about it is shown. */
        it('shows no tally and no cooldown', () => {
            const { container } = render(
                <CompanionPage companion={companion()} />,
            );

            fireEvent.click(screen.getByRole('button', { name: /pet/i }));

            expect(container.textContent ?? '').not.toMatch(
                /again in|\d+\s*(left|remaining|times|more)|cooldown|lonely|hungry|misses you/i,
            );
        });
    });
});
