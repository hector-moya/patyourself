import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    Companion,
    ambientFor,
    arrivingItem,
    describe as label,
    selfStartedFor,
} from './companion';
import { companion, unlock } from './companion.fixture';

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
        const { container, rerender } = render(
            <Companion companion={companion()} reactTo={101} />,
        );

        // Let the reaction finish and hand the channel back. Scoped to this
        // test alone — real timers return immediately after, so no other
        // test in the file inherits a mocked clock.
        vi.useFakeTimers();
        act(() => {
            vi.advanceTimersByTime(0);
        });
        vi.useRealTimers();

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
