import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const page = { url: '/companion', props: { unread_notifications_count: 0 } };

/**
 * `router.post` is stubbed rather than exercised: touching a node is a visit,
 * and what the server does with it has its own feature test. What matters here
 * is that the press reaches the right URL at all.
 */
const post = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        Head: () => null,
        usePage: () => page,
        router: {
            ...actual.router,
            post: (...args: unknown[]) => post(...args),
        },
    };
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
    post.mockClear();
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
            <CompanionPage
                bag={bag()}
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
                            droppable: true,
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
                    // The fixture's own nodes default to available: 0, which
                    // leaves Clearing rendering null and this guard blind to
                    // it. At least one node standing and known so the
                    // clearing group actually renders here.
                    nodes: [
                        {
                            node: 'reeds',
                            label: 'the reeds',
                            available: 4,
                            skill: 'gather-fibre',
                            met: true,
                            known: true,
                            usable: true,
                        },
                    ],
                    // The fixture's own shelter defaults to a null offer, which
                    // leaves Shelter rendering null and this guard blind to it —
                    // a section that renders null is a section the acceptance
                    // criterion is not checking. A non-null offer so the shelter
                    // row actually renders inside this assertion.
                    shelter: {
                        built: null,
                        label: null,
                        offer: {
                            stage: 'lean-to',
                            label: 'lean-to',
                            recipe: { planks: 4 },
                            buildable: false,
                        },
                    },
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
            <CompanionPage
                bag={bag()}
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
        render(
            <CompanionPage bag={bag()} companion={companion()} remark={null} />,
        );

        expect(screen.queryByTestId('companion-remark')).toBeNull();
        expect(screen.queryByText(/nothing to say|no remarks/i)).toBeNull();
    });

    it('names a recoloured item by its variant', () => {
        render(
            <CompanionPage
                bag={bag()}
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
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                />,
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
                <CompanionPage bag={bag({ xp: 48 })} companion={companion()} />,
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
            render(<CompanionPage bag={bag()} companion={companion()} />);

            expect(screen.queryByRole('dialog')).toBeNull();

            fireEvent.click(screen.getByRole('button', { name: /bag/i }));

            expect(screen.getByRole('dialog')).toBeInTheDocument();
        });

        /** And it closes again without taking the page with it. */
        it('closes again on the dialog’s own control', () => {
            render(<CompanionPage bag={bag()} companion={companion()} />);

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
            render(<CompanionPage bag={bag()} companion={companion()} />);

            for (const name of [/pet/i, /play/i, /poke/i, /bag/i]) {
                expect(screen.getByRole('button', { name })).toBeEnabled();
            }
        });

        /**
         * Before Blob exists there is no room, no plinth and nothing gathered,
         * so there is no bag either — the same rule the buttons already follow.
         */
        it('offers no bag before Blob exists', () => {
            render(<CompanionPage bag={bag()} companion={noCompanion()} />);

            expect(screen.queryByRole('button', { name: /bag/i })).toBeNull();
        });
    });

    /**
     * The clearing. Both things are standing in it from the first visit, and a
     * node Blob cannot use looks exactly like one it can — that sameness IS the
     * mechanic, so it is what these cases pin.
     */
    describe('the clearing', () => {
        it('draws everything in the clearing, met or not', () => {
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                />,
            );

            expect(
                screen.getByRole('button', { name: /the reeds/i }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: /the fallen branches/i }),
            ).toBeInTheDocument();
        });

        /**
         * No lock, no dimming, no price. The price lives in the bag, which the
         * first encounter opens — a node you cannot use has to look like one
         * you can, or the invitation to click it disappears.
         */
        it('shows no lock and no price on a node it cannot use', () => {
            const { container } = render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                />,
            );

            const reeds = screen.getByRole('button', { name: /the reeds/i });

            expect(reeds).toBeEnabled();
            expect(reeds).not.toHaveClass('is-known');
            expect(container.textContent ?? '').not.toMatch(
                /locked|requires|need the|learn first/i,
            );
        });

        it('touches the node it names', () => {
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                />,
            );

            fireEvent.click(screen.getByRole('button', { name: /the reeds/i }));

            expect(post).toHaveBeenCalledTimes(1);
            expect(post.mock.calls[0][0]).toContain('/companion/nodes/reeds');
        });

        /** What is standing there, once something is. */
        it('shows what has accrued, and nothing when nothing has', () => {
            render(
                <CompanionPage
                    companion={companion({ scene: 'forest' })}
                    bag={bag({
                        nodes: [
                            {
                                node: 'reeds',
                                label: 'the reeds',
                                available: 4,
                                skill: 'gather-fibre',
                                met: true,
                                known: true,
                                usable: true,
                            },
                            {
                                node: 'deadfall',
                                label: 'the fallen branches',
                                available: 0,
                                skill: 'gather-wood',
                                met: false,
                                known: false,
                                usable: false,
                            },
                        ],
                    })}
                />,
            );

            expect(
                screen.getByRole('button', { name: /the reeds/i }),
            ).toHaveTextContent('4');
            // A zero would be a count of what you have not got.
            expect(
                screen.getByRole('button', { name: /the fallen branches/i }),
            ).toHaveTextContent(/^the fallen branches$/i);
        });

        /** Indoors there is no clearing, so there is nothing to touch. */
        it('draws no clearing in the cabin', () => {
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'cabin' })}
                />,
            );

            expect(
                screen.queryByRole('button', { name: /the reeds/i }),
            ).toBeNull();
        });

        /** What just happened, in the same bubble Blob's remarks use. */
        it('says what the last press did, over the coach’s line', () => {
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                    remark="Blob has been standing by the window."
                    said="Blob turns the reeds over and puts them down again."
                />,
            );

            expect(
                screen.getByText(/turns the reeds over/i),
            ).toBeInTheDocument();
            expect(screen.queryByText(/standing by the window/i)).toBeNull();
        });

        /** A revealed skill opens the bag, once, without being asked. */
        it('opens the bag when an encounter revealed something', () => {
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                    said="Blob turns the reeds over and puts them down again."
                    revealed
                />,
            );

            expect(screen.getByRole('dialog')).toBeInTheDocument();
            // And says it there, because the bubble is behind the overlay.
            expect(
                screen.getByText(/turns the reeds over/i),
            ).toBeInTheDocument();
        });

        it('opens nothing when the encounter revealed nothing', () => {
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ scene: 'forest' })}
                    said="Blob checks the reeds. Nothing has grown back yet."
                />,
            );

            expect(screen.queryByRole('dialog')).toBeNull();
        });

        /**
         * Three things stand in the clearing, all of them from the start. A node
         * you cannot use looks exactly like one you can — that is the whole
         * discovery mechanic, and a lock would undo it.
         */
        it('stands the trunk in the clearing beside the other two', () => {
            render(
                <CompanionPage
                    companion={companion({ scene: 'forest' })}
                    bag={bag({
                        nodes: [
                            {
                                node: 'deadfall',
                                label: 'the fallen branches',
                                available: 0,
                                skill: 'gather-wood',
                                met: false,
                                known: false,
                                usable: false,
                            },
                            {
                                node: 'reeds',
                                label: 'the reeds',
                                available: 0,
                                skill: 'gather-fibre',
                                met: false,
                                known: false,
                                usable: false,
                            },
                            {
                                node: 'trunk',
                                label: 'the fallen trunk',
                                available: 0,
                                skill: 'chop-wood',
                                met: false,
                                known: false,
                                usable: false,
                            },
                        ],
                    })}
                />,
            );

            expect(
                screen.getByRole('button', { name: 'the fallen trunk' }),
            ).toBeEnabled();
        });
    });

    describe('what Blob has to say', () => {
        /** Blob talking, over the scene — not the app captioning the picture. */
        it('puts it on the scene, and lets it be put away', () => {
            const { container } = render(
                <CompanionPage
                    bag={bag()}
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
                <CompanionPage
                    bag={bag()}
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
                <CompanionPage
                    bag={bag()}
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
                <CompanionPage
                    bag={bag()}
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
                <CompanionPage
                    bag={bag()}
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
            render(
                <CompanionPage
                    bag={bag()}
                    companion={companion({ abilities: [] })}
                />,
            );

            expect(screen.queryByRole('button', { name: /wave/i })).toBeNull();
            expect(screen.queryByRole('button', { name: /jump/i })).toBeNull();
        });

        it('draws one once the ladder has announced it', () => {
            render(
                <CompanionPage
                    bag={bag()}
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
                <CompanionPage
                    bag={bag()}
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
                <CompanionPage
                    bag={bag()}
                    companion={companion({ abilities: ['wave', 'jump'] })}
                />,
            );

            for (const name of [/pet/i, /play/i, /wave/i, /jump/i]) {
                expect(screen.getByRole('button', { name })).toBeEnabled();
            }
        });
    });

    /**
     * Where Blob IS and what Blob has BUILT are independent facts, and the
     * control that moves between them only exists once there is something to
     * go into. A "go inside" button with nothing built would be a door to a
     * room that does not exist — which is the preview this feature refuses.
     */
    it('offers no way inside until something has been built', () => {
        render(
            <CompanionPage
                bag={bag()}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.queryByRole('button', { name: /go inside/i })).toBeNull();
    });

    it('goes inside what was built, and comes back out again', () => {
        render(
            <CompanionPage
                bag={bag({
                    shelter: {
                        built: 'lean-to',
                        label: 'lean-to',
                        offer: null,
                    },
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.getByRole('img')).toHaveAttribute('data-scene', 'forest');
        expect(screen.getByRole('img')).not.toHaveAttribute('data-interior');

        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));

        // The scene underneath is unchanged: the forest is always the world.
        expect(screen.getByRole('img')).toHaveAttribute('data-scene', 'forest');
        expect(screen.getByRole('img')).toHaveAttribute(
            'data-interior',
            'lean-to',
        );

        fireEvent.click(screen.getByRole('button', { name: /go outside/i }));

        expect(screen.getByRole('img')).not.toHaveAttribute('data-interior');
    });

    /**
     * Inside is where you are looking, not something you own. A fresh render
     * is the reload, and it always starts outside — the way a modal does.
     */
    it('starts outside every time, because inside is not stored', () => {
        const props = {
            bag: bag({
                shelter: { built: 'cabin', label: 'cabin', offer: null },
            }),
            companion: companion({ scene: 'forest' }),
        };

        const first = render(<CompanionPage {...props} />);
        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));
        expect(screen.getByRole('img')).toHaveAttribute(
            'data-interior',
            'cabin',
        );

        first.unmount();
        render(<CompanionPage {...props} />);

        expect(screen.getByRole('img')).not.toHaveAttribute('data-interior');
    });

    /** Nothing grows indoors: the clearing's own things are not reachable there. */
    it('draws no clearing while Blob is inside', () => {
        render(
            <CompanionPage
                bag={bag({
                    shelter: { built: 'hut', label: 'hut', offer: null },
                    nodes: [
                        {
                            node: 'reeds',
                            label: 'the reeds',
                            available: 4,
                            skill: 'gather-fibre',
                            met: true,
                            known: true,
                            usable: true,
                        },
                    ],
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(
            screen.getByRole('button', { name: /the reeds/i }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));

        expect(screen.queryByRole('button', { name: /the reeds/i })).toBeNull();
    });

    /** The place bar names where Blob actually is, not where the record says. */
    it('names the place Blob is standing in', () => {
        render(
            <CompanionPage
                bag={bag({
                    shelter: { built: 'hut', label: 'hut', offer: null },
                })}
                companion={companion({ scene: 'forest' })}
            />,
        );

        expect(screen.getByText('the forest')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /go inside/i }));

        expect(screen.getByText('the hut')).toBeInTheDocument();
        expect(screen.queryByText('the forest')).toBeNull();
    });
});
