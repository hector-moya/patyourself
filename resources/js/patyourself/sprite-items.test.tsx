import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import { SPRITE_ITEMS } from './sprite-items';
import { CELL, FORMS, anchorFor } from './sprite-layout';
import type { SpriteForm } from './sprite-layout';

/** Where each form's feet touch the ground — the lowest opaque row. */
const SOLE: Record<string, number> = { blob: 51, legs: 53, arms: 53 };

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
     *
     * `face.x` is a literal here, not `ARMS_FORM.anchors.face[0]` — reading
     * it live would let this test's own expected value drift along with a
     * regression in the anchor table itself, since the same (wrong) number
     * would feed both sides of the comparison (review round 3). Asserting
     * it separately below covers the anchor's own x, not just the lens's
     * offset relative to it.
     */
    it('pins each lens to the eyes it is meant to sit on, not beside them', () => {
        expect(ARMS_FORM.anchors.face[0]).toBe(-1);

        const faceX = -1;
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
     *
     * `feet.x` is a literal here, not read from `form.anchors.feet[0]` — the
     * renderer itself subtracts that live value (`sprite-items.tsx`'s
     * `shoes.render`), so a test that read the same live value would agree
     * with a wrong one instead of catching it (review round 3, the same gap
     * as the glasses test above).
     */
    it('lands each shoe on the measured foot columns, not 1px beside them', () => {
        for (const form of [BLOB_FORM, LEGS_FORM, ARMS_FORM]) {
            expect(form.anchors.feet[0]).toBe(-1);
        }

        const anchorX = -1;
        const columnsOf = (form: SpriteForm) => {
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

    /**
     * The two `sprite-layout.test.ts` guards pin the `neck` *anchor*
     * (`neck === face + 9`, and its delta tracking `face`'s) — nothing
     * pinned the scarf's *own* rect offsets the way lenses are pinned to
     * eye columns and shoes to foot segments above. Reverting
     * `scarf.render`'s `y` values to their pre-round-2 numbers (`y={5}` /
     * `y={8}`, tuned against the old, wrong `neck` anchor) left the whole
     * suite green while drawing the band on the feet and running the tail
     * past the sole — exactly the regression round 2 was dispatched to fix
     * (review round 3).
     *
     * Checked across every animation and frame each form has, not just the
     * resting anchor: `neck` moves during several animations, so a check
     * that only looked at the base position could pass while a mid-
     * animation frame pushed the scarf onto the feet.
     *
     * The tail's worst-case row (`blob`'s own idle frame 0, and `arms`'s
     * `jump` frame 0) lands *exactly* on the sole row for both forms — the
     * tail is drawn long enough to reach the foot without dangling past it,
     * by design. So "clear of the sole" is bounded with `<=`, not `<`: the
     * regression this guards against overshoots by several rows (see the
     * sabotage in the report), not by one.
     */
    it('keeps the scarf below the mouth and clear of the sole, on every frame', () => {
        for (const form of FORMS) {
            const [band, tail] = drawItem(
                'scarf',
                '#000',
                form,
            ).querySelectorAll('rect');
            const bandY = Number(band.getAttribute('y'));
            const tailY = Number(tail.getAttribute('y'));
            const tailHeight = Number(tail.getAttribute('height'));
            const sole = SOLE[form.feature];

            for (const animation of form.animations) {
                for (
                    let frame = 0;
                    frame < ANIMATIONS[animation].frames;
                    frame += 1
                ) {
                    const [, neckY] = anchorFor(form, 'neck', animation, frame);
                    const [, faceY] = anchorFor(form, 'face', animation, frame);
                    const mouth = faceY + 6;

                    expect(neckY + bandY).toBeGreaterThan(mouth);
                    expect(neckY + tailY + tailHeight - 1).toBeLessThanOrEqual(
                        sole,
                    );
                }
            }
        }
    });
});
