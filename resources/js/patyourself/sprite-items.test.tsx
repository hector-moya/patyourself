import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { SPRITE_ITEMS } from './sprite-items';
import { CELL, FORMS } from './sprite-layout';
import type { SpriteForm } from './sprite-layout';

const BLOB_FORM = FORMS[0];
const LEGS_FORM = FORMS[1];
const ARMS_FORM = FORMS[FORMS.length - 1];

function drawItem(
    type: string,
    colour = '#123456',
    form: SpriteForm = ARMS_FORM,
) {
    return render(<svg>{SPRITE_ITEMS[type].render(colour, form)}</svg>)
        .container;
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

    /**
     * The eyes sit at columns 24–28 and 36–40 on every form (sprites/
     * README.md). Bounding an item to the 64px cell (see blob-renderer.test
     * .tsx) cannot tell "on the eye" from "3px beside it" — this pins each
     * lens to the actual measured columns instead. Converts the rect's own
     * anchor-relative `x` back to an absolute column the same way the real
     * renderer composites it: `CELL / 2 + face.x + rect.x`.
     */
    it('pins each lens to the eyes it is meant to sit on, not beside them', () => {
        const [faceX] = ARMS_FORM.anchors.face;
        const container = drawItem('glasses', '#000', ARMS_FORM);
        const columnsOf = (rect: Element) => {
            const x = Number(rect.getAttribute('x'));
            const width = Number(rect.getAttribute('width'));

            return [CELL / 2 + faceX + x, CELL / 2 + faceX + x + width - 1];
        };
        const [leftLens, rightLens] = container.querySelectorAll('rect');

        expect(columnsOf(leftLens)).toEqual([24, 28]);
        expect(columnsOf(rightLens)).toEqual([36, 40]);
    });

    it('hangs each type off the anchor that suits it', () => {
        expect(SPRITE_ITEMS.hat.anchor).toBe('head');
        // Glasses hang off the eye line, not the skull top — hanging them
        // off `head` is what put them on the mouth during `notice` and on
        // the forehead during `jump`, since `head` and `face` do not move
        // together on every frame.
        expect(SPRITE_ITEMS.glasses.anchor).toBe('face');
        expect(SPRITE_ITEMS.scarf.anchor).toBe('neck');
        expect(SPRITE_ITEMS.shoes.anchor).toBe('feet');
    });

    /**
     * `blob` has no legs, so its foot nubs are wider and set further apart
     * than the narrower feet `legs` and `arms` share (see `FOOT_RECTS` in
     * sprite-items.tsx). One shared rect pair cannot fit both without
     * either sitting inboard on the walking forms or spilling past the
     * nub on `blob`.
     */
    it("fits each form's own feet rather than one shared shoe", () => {
        const blobX = Array.from(
            drawItem('shoes', '#000', BLOB_FORM).querySelectorAll('rect'),
        ).map((rect) => rect.getAttribute('x'));
        const legsX = Array.from(
            drawItem('shoes', '#000', LEGS_FORM).querySelectorAll('rect'),
        ).map((rect) => rect.getAttribute('x'));

        expect(blobX).not.toEqual(legsX);
        // legs and arms grow the same feet, so they share geometry.
        const armsX = Array.from(
            drawItem('shoes', '#000', ARMS_FORM).querySelectorAll('rect'),
        ).map((rect) => rect.getAttribute('x'));

        expect(legsX).toEqual(armsX);
    });

    /**
     * The per-form guard above only proves `blob` differs from `legs` — it
     * would not catch every shoe landing a fixed 1px off the mark in the
     * same direction, which is exactly what happened when `FOOT_RECTS`
     * (measured from the cell's centre line) was applied without correcting
     * for the `feet` anchor's own `x` (−1, not 0). Pins the rendered
     * columns to the measured foot segments themselves (sprites/README.md).
     */
    it('lands each shoe on the measured foot columns, not 1px beside them', () => {
        const columnsOf = (form: SpriteForm) => {
            const [anchorX] = form.anchors.feet;

            return Array.from(
                drawItem('shoes', '#000', form).querySelectorAll('rect'),
            ).map((rect) => {
                const x = Number(rect.getAttribute('x'));
                const width = Number(rect.getAttribute('width'));

                return [
                    CELL / 2 + anchorX + x,
                    CELL / 2 + anchorX + x + width - 1,
                ];
            });
        };

        expect(columnsOf(BLOB_FORM)).toEqual([
            [17, 28],
            [35, 45],
        ]);
        expect(columnsOf(LEGS_FORM)).toEqual([
            [21, 29],
            [35, 43],
        ]);
        expect(columnsOf(ARMS_FORM)).toEqual([
            [21, 29],
            [35, 43],
        ]);
    });
});
