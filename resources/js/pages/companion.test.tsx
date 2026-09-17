import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const page = { url: '/companion', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import {
    bag,
    companion,
    noCompanion,
    unlock,
} from '@/patyourself/companion.fixture';

import CompanionPage from './companion';

// A few cases below pin the wall clock, which the place bar and the ambient
// both read. Restored here rather than in each one, so a failing assertion
// cannot leave `Date` faked for everything that follows it.
afterEach(() => {
    vi.useRealTimers();
});

describe('Companion screen', () => {
    /**
     * Nothing yet is stated as a fact about the record, with nothing owed and
     * nothing to act on — and no empty room either, since there is nobody to
     * put in it.
     */
    it('says what brings Blob out, without asking for it', () => {
        render(<CompanionPage bag={bag()} companion={noCompanion()} />);

        expect(screen.getByText(/blob turns up once/i)).toBeInTheDocument();
        expect(screen.queryByText(/locked|to unlock|remaining/i)).toBeNull();
        expect(screen.queryByRole('button', { name: /pet/i })).toBeNull();
    });

    it('lists what has happened, newest first, with the date each arrived', () => {
        render(
            <CompanionPage bag={bag()}
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
        render(<CompanionPage bag={bag()} companion={companion()} />);

        expect(
            screen.queryByText(
                /locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i,
            ),
        ).toBeNull();
    });

    /**
     * The same guard, with the bag open.
     *
     * Two cases rather than one because the guard queries the rendered tree,
     * and a modal that only exists after a click would otherwise never be
     * inside it — the surface most at risk of showing a total would be the one
     * surface the acceptance criterion never looked at.
     */
    it('still shows nothing that has not happened once the bag is open', () => {
        render(
            <CompanionPage
                bag={bag({
                    xp: 48,
                    held: 3,
                    capacity: 5,
                    items: [
                        {
                            item: 'fibre',
                            label: 'fibre',
                            category: 'material',
                            quantity: 3,
                        },
                    ],
                    skills: [
                        {
                            skill: 'gather-fibre',
                            label: 'gather fibre',
                            price: 20,
                            known: false,
                            affordable: true,
                        },
                    ],
                })}
                companion={companion()}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /bag/i }));

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(
            screen.queryByText(
                /locked|next up|to unlock|remaining|streak|congratulation|\d+\s*%|\d+ of \d+/i,
            ),
        ).toBeNull();
    });

    it('relays what Blob has to say, near Blob', () => {
        render(
            <CompanionPage bag={bag()}
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
        render(<CompanionPage bag={bag()} companion={companion()} remark={null} />);

        expect(screen.queryByTestId('companion-remark')).toBeNull();
        expect(screen.queryByText(/nothing to say|no remarks/i)).toBeNull();
    });

    it('names a recoloured item by its variant', () => {
        render(
            <CompanionPage bag={bag()}
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

    /**
     * Where Blob is and when — two facts the drawing already carried and the
     * screen never said out loud. Both come off the same hour the room lights
     * itself by, which is the point: a bar reading "day" over a night-lit
     * forest would be two clocks, and two clocks is how the light starts
     * looking broken rather than deliberate.
     */
    describe('the place bar', () => {
        it('names where Blob is and what part of the day it is', () => {
            vi.useFakeTimers({ toFake: ['Date'] });
            vi.setSystemTime(new Date('2026-09-11T19:30:00'));

            render(
                <CompanionPage bag={bag()} companion={companion({ scene: 'forest' })} />,
            );

            expect(screen.getByText('the forest')).toBeInTheDocument();
            expect(screen.getByText('dusk · 19:30')).toBeInTheDocument();
        });

        it('reads the same hour the room and the ambient do', () => {
            vi.useFakeTimers({ toFake: ['Date'] });
            vi.setSystemTime(new Date('2026-09-11T22:00:00'));

            const { container } = render(
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            expect(screen.getByText('night · 22:00')).toBeInTheDocument();
            expect(
                container
                    .querySelector('.blob-room')
                    ?.getAttribute('data-part-of-day'),
            ).toBe('night');
            expect(
                container
                    .querySelector('.blob-anim')
                    ?.getAttribute('data-animation'),
            ).toBe('sleep');
        });
    });

    /**
     * XP and the bag: the balance stated, and the bag behind a button rather
     * than on the page. Nothing here is a target — the moment a number grows a
     * ceiling, the checklist is back in through the window.
     */
    describe('the balance and the bag', () => {
        it('shows the balance, and nothing to reach', () => {
            render(
                <CompanionPage
                    bag={bag({ xp: 48 })}
                    companion={companion()}
                />,
            );

            expect(screen.getByText('48 xp')).toBeInTheDocument();
            expect(
                screen.queryByText(/next at|target|of \d+ xp|\d+ xp to/i),
            ).toBeNull();
        });

        /** The bag's one ambient fact, on the thing that opens it. */
        it('carries the capacity on the button', () => {
            render(
                <CompanionPage
                    bag={bag({ held: 3, capacity: 10 })}
                    companion={companion()}
                />,
            );

            expect(
                screen.getByRole('button', { name: /bag/i }),
            ).toHaveTextContent('3 / 10');
        });

        /** Closed until asked for. Nothing opens on arrival. */
        it('keeps the bag shut until the button is pressed', () => {
            render(
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            expect(screen.queryByRole('dialog')).toBeNull();

            fireEvent.click(screen.getByRole('button', { name: /bag/i }));

            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        /** And it closes again without taking the page with it. */
        it('closes again on the dialog’s own control', () => {
            render(
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            fireEvent.click(screen.getByRole('button', { name: /bag/i }));
            fireEvent.click(
                screen.getByRole('button', { name: /close the bag/i }),
            );

            expect(screen.queryByRole('dialog')).toBeNull();
            expect(
                screen.getByRole('button', { name: /pet/i }),
            ).toBeInTheDocument();
        });

        /** Adding a button never takes one away. */
        it('keeps the other plinth buttons alongside it', () => {
            render(
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            for (const name of [/pet/i, /play/i, /poke/i, /bag/i]) {
                expect(screen.getByRole('button', { name })).toBeEnabled();
            }
        });

        /**
         * Before Blob exists there is no room, no plinth and nothing gathered,
         * so there is no bag either — the same rule the buttons already follow.
         */
        it('offers no bag before Blob exists', () => {
            render(
                <CompanionPage bag={bag()} companion={noCompanion()} />,
            );

            expect(screen.queryByRole('button', { name: /bag/i })).toBeNull();
        });
    });

    describe('what Blob has to say', () => {
        /** Blob talking, over the scene — not the app captioning the picture. */
        it('puts it on the scene, and lets it be put away', () => {
            const { container } = render(
                <CompanionPage bag={bag()}
                    companion={companion()}
                    remark="Blob watched the grass move for a while."
                />,
            );

            expect(container.querySelector('.c-stage .c-said')).not.toBeNull();

            fireEvent.click(screen.getByRole('button', { name: /dismiss/i }));

            expect(screen.queryByTestId('companion-remark')).toBeNull();
        });

        /**
         * The one case the bubble cannot cover: before Blob exists there is no
         * scene to hang it on, and the line still has to be relayed. The
         * server decides whether there is one, not this screen — see the note
         * on `nothingYet` in the page.
         */
        it('still relays it before Blob exists, as plain type', () => {
            render(
                <CompanionPage bag={bag()}
                    companion={noCompanion()}
                    remark="Blob is nearly here."
                />,
            );

            expect(screen.getByTestId('companion-remark')).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /dismiss/i }),
            ).toBeNull();
        });
    });

    describe('poking Blob', () => {
        it('reacts when the scene itself is touched', () => {
            const { container } = render(
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            fireEvent.click(container.querySelector('.blob-room') as Element);

            expect(
                container
                    .querySelector('.blob-anim')
                    ?.getAttribute('data-animation'),
            ).toBe('notice');
        });

        /** The same reaction on a real button, so it is reachable by keyboard. */
        it('offers the same thing as a button', () => {
            const { container } = render(
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            fireEvent.click(screen.getByRole('button', { name: /poke/i }));

            expect(
                container
                    .querySelector('.blob-anim')
                    ?.getAttribute('data-animation'),
            ).toBe('notice');
        });
    });

    describe('the record', () => {
        it('says when it began, and marks only the newest arrival', () => {
            render(
                <CompanionPage bag={bag()}
                    companion={companion({
                        features: ['blob', 'legs'],
                        unlocks: [
                            unlock({
                                unlocked_at: '2026-08-20T09:00:00+00:00',
                            }),
                            unlock({
                                name: 'legs',
                                message: 'Blob has legs now.',
                                unlocked_at: '2026-08-27T09:00:00+00:00',
                            }),
                        ],
                    })}
                />,
            );

            expect(screen.getByText('since 20 Aug 2026')).toBeInTheDocument();
            expect(screen.getAllByText('newest')).toHaveLength(1);
            expect(screen.getAllByRole('listitem')[0]).toHaveTextContent(
                'legs',
            );

            // `toHaveClass`, which splits on whitespace, and never
            // `toContain` — the failure this guards is a marker class
            // concatenated onto the layout class with no space between
            // them, and a substring check passes on exactly that.
            const [newest, older] = screen.getAllByRole('listitem');

            expect(newest).toHaveClass('c-entry', 'is-new');
            expect(older).toHaveClass('c-entry');
            expect(older).not.toHaveClass('is-new');
        });

        it('marks each line with the kind of thing that arrived', () => {
            const { container } = render(
                <CompanionPage bag={bag()}
                    companion={companion({
                        items: [{ type: 'shoes', variant: null }],
                        abilities: ['wave'],
                        unlocks: [
                            unlock(),
                            unlock({
                                kind: 'ability',
                                name: 'wave',
                                message: 'Blob can wave.',
                            }),
                            unlock({
                                kind: 'item',
                                name: 'shoes',
                                message: 'Blob has shoes now.',
                            }),
                        ],
                    })}
                />,
            );

            const kinds = [
                ...container.querySelectorAll('.c-log .companion-glyph'),
            ].map((glyph) => glyph.getAttribute('data-glyph'));

            expect(kinds).toEqual(['item', 'ability', 'body']);
        });

        /** No dangling "since" when the record carries no date to name. */
        it('says nothing about when it began if nothing is dated', () => {
            render(
                <CompanionPage bag={bag()}
                    companion={companion({
                        unlocks: [unlock({ unlocked_at: null })],
                        latest_unlock: unlock({ unlocked_at: null }),
                    })}
                />,
            );

            expect(screen.queryByText(/^since/i)).toBeNull();
        });
    });

    describe('pet and play', () => {
        /**
         * Always enabled. No cooldown, no daily limit, no counter and no meter
         * — pressing them is not progress and Blob never asks to be pressed.
         */
        it('offers both, with nothing gating them', () => {
            render(<CompanionPage bag={bag()} companion={companion()} />);

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
                <CompanionPage bag={bag()} companion={companion()} />,
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
                <CompanionPage bag={bag()} companion={companion()} />,
            );

            fireEvent.click(screen.getByRole('button', { name: /pet/i }));

            expect(container.textContent ?? '').not.toMatch(
                /again in|\d+\s*(left|remaining|times|more)|cooldown|lonely|hungry|misses you/i,
            );
        });
    });

    describe('asking Blob to use an ability', () => {
        /**
         * The rule the row turns on: an ability Blob has not learned is not
         * drawn at all. Not disabled, not greyed, not a placeholder — a slot
         * standing empty is a preview of what is coming, and this screen only
         * ever shows what has happened.
         */
        it('draws no button for an ability Blob has not learned', () => {
            render(<CompanionPage bag={bag()} companion={companion({ abilities: [] })} />);

            expect(screen.queryByRole('button', { name: /wave/i })).toBeNull();
            expect(screen.queryByRole('button', { name: /jump/i })).toBeNull();
        });

        it('draws one once the ladder has announced it', () => {
            render(
                <CompanionPage bag={bag()}
                    companion={companion({ abilities: ['wave'] })}
                />,
            );

            expect(
                screen.getByRole('button', { name: /wave/i }),
            ).toBeInTheDocument();
            // Only the one that was earned.
            expect(screen.queryByRole('button', { name: /jump/i })).toBeNull();
        });

        it('plays the ability it names, not some other one', () => {
            const { container } = render(
                <CompanionPage bag={bag()}
                    companion={companion({ abilities: ['wave'] })}
                />,
            );

            fireEvent.click(screen.getByRole('button', { name: /wave/i }));

            expect(
                container
                    .querySelector('.blob-anim')
                    ?.getAttribute('data-animation'),
            ).toBe('wave');
        });

        /** Earning an ability adds a button; it never takes one away. */
        it('keeps the two ungated ones alongside it', () => {
            render(
                <CompanionPage bag={bag()}
                    companion={companion({ abilities: ['wave', 'jump'] })}
                />,
            );

            for (const name of [/pet/i, /play/i, /wave/i, /jump/i]) {
                expect(screen.getByRole('button', { name })).toBeEnabled();
            }
        });
    });
});
