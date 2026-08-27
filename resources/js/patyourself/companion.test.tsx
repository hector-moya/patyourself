import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Companion } from './companion';
import type { CompanionData, CompanionUnlockData } from './companion';

function unlock(
    overrides: Partial<CompanionUnlockData> = {},
): CompanionUnlockData {
    return {
        kind: 'body',
        name: 'blob',
        variant: null,
        message: 'Blob is here.',
        unlocked_at: '2026-08-27T09:00:00+00:00',
        ...overrides,
    };
}

function companion(overrides: Partial<CompanionData> = {}): CompanionData {
    const state: CompanionData = {
        log_count: 1,
        insight_count: 0,
        stage_index: 1,
        features: ['blob'],
        items: [],
        abilities: [],
        unlocks: [unlock()],
        latest_unlock: unlock(),
        ...overrides,
    };

    return state;
}

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

    it('draws a body and two eyes once Blob exists', () => {
        const { container } = render(<Companion companion={companion()} />);

        expect(container.querySelector('.blob-body')).not.toBeNull();
        expect(container.querySelectorAll('.blob-body circle')).toHaveLength(2);
        expect(container.querySelector('.blob-legs')).toBeNull();
    });

    it('adds legs only once they have been earned', () => {
        const { container } = render(
            <Companion companion={companion({ features: ['blob', 'legs'] })} />,
        );

        expect(container.querySelectorAll('.blob-legs rect')).toHaveLength(2);
    });

    /**
     * The layering rule: an accessory is positioned by its anchor and never by
     * the body's own geometry, so adding one cannot move Blob.
     */
    it('hangs each item off its anchor', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    features: ['blob', 'legs'],
                    items: [
                        { type: 'shoes', variant: null },
                        { type: 'hat', variant: null },
                    ],
                })}
            />,
        );

        const layers = Array.from(
            container.querySelectorAll('.blob-layer'),
        ).map((layer) => layer.getAttribute('transform'));

        expect(layers).toEqual(['translate(0 40)', 'translate(0 0)']);
    });

    it('recolours an item when the unlock names a variant', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    items: [{ type: 'scarf', variant: 'coral' }],
                })}
            />,
        );

        const scarf = container.querySelector('.blob-layer rect');

        expect(scarf?.getAttribute('fill')).toBe('#E8836B');
    });

    /**
     * The only transition in the feature, and it is on the layer just earned.
     * Everything already there is simply there.
     */
    it('fades in only the layer earned most recently', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    items: [
                        { type: 'shoes', variant: null },
                        { type: 'scarf', variant: null },
                    ],
                    latest_unlock: unlock({ kind: 'item', name: 'scarf' }),
                })}
            />,
        );

        const arriving = container.querySelectorAll('.blob-layer--arriving');

        expect(arriving).toHaveLength(1);
        expect(arriving[0].getAttribute('transform')).toBe('translate(0 31)');
    });

    /**
     * The ladder can name an ability or an item before anyone draws it — that is
     * what keeps adding a stage a config edit. An undrawn one is skipped rather
     * than rendered as a hole.
     */
    it('skips an item type it has not drawn yet', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    items: [{ type: 'umbrella', variant: null }],
                })}
            />,
        );

        expect(container.querySelectorAll('.blob-layer')).toHaveLength(0);
        expect(container.querySelector('.blob-body')).not.toBeNull();
    });

    it('applies an ability as a class on the root', () => {
        const { container } = render(
            <Companion
                companion={companion({
                    features: ['blob', 'legs'],
                    abilities: ['walk', 'wave'],
                })}
            />,
        );

        const svg = container.querySelector('svg');

        expect(svg?.classList.contains('blob-walk')).toBe(true);
        // `wave` is on the ladder but not yet drawn; it contributes no class
        // rather than an undefined one.
        expect(svg?.className.baseVal).not.toContain('undefined');
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

        const label = screen.getByRole('img').getAttribute('aria-label') ?? '';

        expect(label).toBe('Blob, with shoes, walk');
        expect(label).not.toMatch(/streak|level|%|of \d/i);
    });
});
