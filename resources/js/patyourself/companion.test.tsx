import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { __resetSpriteClock } from '@/hooks/use-sprite-clock';
import {
    Companion,
    actionsFor,
    ambientFor,
    arrivingItem,
    describe as label,
    selfStartedFor,
} from './companion';
import { companion, unlock } from './companion.fixture';

// The clock behind Companion is a module-level singleton (see
// use-sprite-clock.test.ts). Only one test below drives it by hand with a
// stubbed requestAnimationFrame, but the teardown runs unconditionally so a
// failed assertion still can't leak a stubbed clock into the next test.
afterEach(() => {
    vi.unstubAllGlobals();
    __resetSpriteClock();
});

/**
 * Same stub as use-sprite-clock.test.ts: jsdom has no real matchMedia, so this
 * overwrites the never-matching stub from test/setup.ts for the duration of a
 * test. Reset to `false` before every test in this file — a direct assignment
 * outlives `vi.unstubAllGlobals()`, which only undoes `vi.stubGlobal` stubs.
 */
function reduceMotion(matches: boolean): void {
    window.matchMedia = ((query: string) =>
        ({
            matches: matches && query.includes('prefers-reduced-motion'),
            media: query,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        }) as unknown as MediaQueryList) as typeof window.matchMedia;
}

beforeEach(() => {
    reduceMotion(false);
});

describe('Companion', () => {
    /**
     * Before the first outcome there is no Blob, and no outline of one either.
     * A placeholder in the shape of a reward is a thing the user is behind on.
     */
    it('renders nothing before Blob exists', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    features: [],
                    stage_index: 0,
                    unlocks: [],
                    latest_unlock: null,
                })}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('puts a viewBox around the drawing and runs the clock', () => {
        const { container } = render(<Companion companion={companion()} />);

        const svg = container.querySelector('svg');

        expect(svg?.getAttribute('viewBox')).toBe('-32 -22 64 84');
        expect(
            svg?.querySelector('.blob-anim')?.getAttribute('data-animation'),
        ).toBe('idle');
    });

    /**
     * One component in both places. The geometry lives in the viewBox, so the
     * corner instance and the full-size one are the same drawing.
     */
    it('is the same drawing at 32px and at 300px', () => {
        const small = render(
            <Companion companion={companion()} size={32} />,
        ).container.querySelector('svg');
        const large = render(
            <Companion companion={companion()} size={300} />,
        ).container.querySelector('svg');

        expect(small?.getAttribute('viewBox')).toBe(
            large?.getAttribute('viewBox'),
        );
        expect(small?.getAttribute('width')).toBe('32');
        expect(large?.getAttribute('width')).toBe('300');
    });

    it('honours the renderer flag from config', () => {
        const { container } = render(
            <Companion companion={companion({ renderer: 'sprite' })} />,
        );

        expect(container.querySelector('.blob-body')).toBeNull();
    });

    it('describes what Blob has, without scoring it', () => {
        render(
            <Companion
                companion={companion({
                    features: ['blob', 'legs'],
                    items: [{ type: 'shoes', variant: null }],
                    abilities: ['walk'],
                })}
            />,
        );

        const description =
            screen.getByRole('img').getAttribute('aria-label') ?? '';

        expect(description).toBe('Blob, with shoes, walk');
        expect(description).not.toMatch(/streak|level|%|of \d/i);
    });

    /**
     * The reward has to arrive with the act that earned it. A number rather
     * than a boolean because two outcomes logged in a row have to fire twice,
     * and a flag that is already `true` never changes.
     */
    it('reacts when handed an outcome id', () => {
        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={null} />,
        );

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');

        rerender(<Companion companion={companion()} reactTo={101} />);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');
    });

    it('reacts again when a second outcome is logged', () => {
        // The shared clock only advances when something drives its
        // requestAnimationFrame loop, so proving the first reaction actually
        // ends means driving it by hand rather than assuming it did — see
        // use-sprite-clock.test.ts for the pattern this borrows.
        let pending: FrameRequestCallback[] = [];
        let handle = 0;

        vi.stubGlobal(
            'requestAnimationFrame',
            (callback: FrameRequestCallback): number => {
                pending.push(callback);

                return ++handle;
            },
        );
        vi.stubGlobal('cancelAnimationFrame', vi.fn());

        const tick = (now: number) => {
            const due = pending;
            pending = [];

            act(() => {
                for (const callback of due) {
                    callback(now);
                }
            });
        };

        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={101} />,
        );

        // Establishes the reaction's start time on the clock.
        tick(0);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');

        // notice is 4 frames at 8fps: 500ms finishes it and hands the channel
        // back to the ambient. Asserted explicitly, so this proves the first
        // reaction ended rather than assuming it.
        tick(500);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');

        rerender(<Companion companion={companion()} reactTo={102} />);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('notice');
    });

    /** A plain visit, with nothing just recorded, must not make Blob move. */
    it('does not react without an outcome id', () => {
        const { container, rerender } = render(
            <Companion companion={companion()} />,
        );

        rerender(<Companion companion={companion()} />);

        expect(
            container
                .querySelector('.blob-anim')
                ?.getAttribute('data-animation'),
        ).toBe('idle');
    });

    /**
     * `notice` is unprompted — nobody pressed a button, an outcome was just
     * logged elsewhere. Under reduced motion there is no loop left to run the
     * one-shot back down once it lands, so firing it here would leave Blob
     * stuck in the noticed pose for the rest of the page's life. The correct
     * behaviour is silence: Blob stays on the ambient, exactly as if `reactTo`
     * had never changed.
     */
    it('does not react to a new outcome id under reduced motion', () => {
        reduceMotion(true);

        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={null} />,
        );

        rerender(<Companion companion={companion()} reactTo={101} />);

        const blob = container.querySelector('.blob-anim');

        expect(blob?.getAttribute('data-animation')).toBe('idle');
        expect(blob?.getAttribute('data-frame')).toBe('0');
    });
});

describe('ambientFor', () => {
    /** Walking is what Blob does at rest once it can walk. Same channel. */
    it('is walk once walking is unlocked, and idle before that', () => {
        expect(ambientFor(companion())).toBe('idle');
        expect(ambientFor(companion({ abilities: ['walk'] }))).toBe('walk');
    });
});

describe('arrivingItem', () => {
    it('is the latest unlock when that was something Blob wears', () => {
        expect(
            arrivingItem(
                companion({
                    latest_unlock: unlock({
                        kind: 'item',
                        name: 'scarf',
                        variant: 'coral',
                    }),
                }),
            ),
        ).toEqual({ type: 'scarf', variant: 'coral' });
    });

    it('is nothing when the latest unlock was not worn', () => {
        expect(
            arrivingItem(
                companion({
                    latest_unlock: unlock({ kind: 'ability', name: 'walk' }),
                }),
            ),
        ).toBeNull();
    });
});

describe('describe', () => {
    it('names Blob alone when it owns nothing yet', () => {
        expect(label(companion())).toBe('Blob');
    });
});

describe('selfStartedFor', () => {
    it('lets every Blob blink, and nothing else, before anything is earned', () => {
        expect(selfStartedFor(companion({ abilities: [] }))).toEqual(['blink']);
    });

    it('adds an ability that has a self-starting animation', () => {
        expect(selfStartedFor(companion({ abilities: ['wave'] }))).toContain(
            'wave',
        );
    });

    it('ignores an ability that has no animation to start', () => {
        // `carry` is a prop, not a pose: it draws, but it never plays.
        expect(selfStartedFor(companion({ abilities: ['carry'] }))).toEqual([
            'blink',
        ]);
    });

    it('ignores `walk`, which is the ambient rather than a one-shot', () => {
        expect(selfStartedFor(companion({ abilities: ['walk'] }))).toEqual([
            'blink',
        ]);
    });
});

describe('actionsFor', () => {
    const names = (data: Parameters<typeof actionsFor>[0]) =>
        actionsFor(data).map((action) => action.animation);

    it('always offers the two things done to Blob rather than by it', () => {
        expect(names(companion({ abilities: [] }))).toEqual(['pet', 'play']);
    });

    it('offers an ability once Blob has learned it', () => {
        expect(names(companion({ abilities: ['wave'] }))).toContain('wave');
        expect(names(companion({ abilities: ['jump'] }))).toContain('jump');
    });

    /**
     * The rule the whole row turns on. An ability Blob has not learned is
     * absent, not disabled — a greyed button is an empty slot, and an empty
     * slot is a to-do list.
     */
    it('never offers an ability Blob has not learned', () => {
        expect(names(companion({ abilities: [] }))).not.toContain('wave');
        expect(names(companion({ abilities: ['wave'] }))).not.toContain('jump');
    });

    it('ignores an ability with nothing to press', () => {
        // `carry` is a prop, not a pose: it draws, but it never plays.
        expect(names(companion({ abilities: ['carry'] }))).toEqual([
            'pet',
            'play',
        ]);
    });

    it('ignores `walk`, which is the ambient rather than a one-shot', () => {
        expect(names(companion({ abilities: ['walk'] }))).toEqual([
            'pet',
            'play',
        ]);
    });

    it('gives every action a label to put on its button', () => {
        for (const action of actionsFor(
            companion({ abilities: ['wave', 'jump'] }),
        )) {
            expect(action.label).toMatch(/^[A-Z][a-z]+$/);
        }
    });
});
