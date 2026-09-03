import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SPRITE_ITEMS } from './sprite-items';

function drawItem(type: string, colour = '#123456') {
    return render(<svg>{SPRITE_ITEMS[type].render(colour)}</svg>).container;
}

describe('sprite items', () => {
    it('covers every item type the ladder can name', () => {
        for (const type of ['shoes', 'scarf', 'hat', 'glasses']) {
            expect(SPRITE_ITEMS[type]).toBeDefined();
        }
    });

    /**
     * Rounded corners and strokes are anti-aliased by the browser, which
     * is exactly the soft edge pixel art must not have. Hard rectangles at
     * integer coordinates are indistinguishable from pixels.
     */
    it('draws only hard-edged rectangles on integer coordinates', () => {
        for (const type of Object.keys(SPRITE_ITEMS)) {
            const container = drawItem(type);

            expect(container.querySelectorAll('circle')).toHaveLength(0);
            expect(container.querySelectorAll('path')).toHaveLength(0);

            for (const rect of container.querySelectorAll('rect')) {
                expect(rect.getAttribute('rx')).toBeNull();
                expect(rect.getAttribute('stroke')).toBeNull();

                for (const attribute of ['x', 'y', 'width', 'height']) {
                    expect(
                        Number.isInteger(Number(rect.getAttribute(attribute))),
                    ).toBe(true);
                }
            }
        }
    });

    it('paints with the colour it is handed', () => {
        const container = drawItem('hat', '#ABCDEF');

        expect(container.querySelector('rect')!.getAttribute('fill')).toBe(
            '#ABCDEF',
        );
    });

    it('hangs each type off the anchor that suits it', () => {
        expect(SPRITE_ITEMS.hat.anchor).toBe('head');
        expect(SPRITE_ITEMS.glasses.anchor).toBe('head');
        expect(SPRITE_ITEMS.scarf.anchor).toBe('neck');
        expect(SPRITE_ITEMS.shoes.anchor).toBe('feet');
    });
});
