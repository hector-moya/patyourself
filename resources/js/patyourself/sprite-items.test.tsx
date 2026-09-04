import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import { SPRITE_ABILITIES, SPRITE_ITEMS } from './sprite-items';
import { CELL, FORMS, anchorFor } from './sprite-layout';
import type { SpriteForm } from './sprite-layout';

/**
 * Where each form's feet touch the ground — the lowest opaque row, which is
 * also `form.foot`. Held as literals so a regression in the layout table
 * cannot drift this file's expectations along with it, and tied back to the
 * table below so the copy cannot rot instead.
 */
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

function drawAbility(name: string) {
    return render(<svg>{SPRITE_ABILITIES[name].render()}</svg>).container;
}

/**
 * Every cell pixel a layer's rects cover, given where that layer hangs.
 *
 * `x` is anchor-relative and the anchor's own `x` is measured from the cell's
 * centre column, so a rect's column is `CELL / 2 + anchorX + x` — the same
 * arithmetic the renderer does with its `+ CELL / 2` correction, and the same
 * the lens and shoe guards above do.
 */
function coveredBy(
    container: Element,
    [anchorX, anchorY]: readonly [number, number],
): Set<string> {
    const covered = new Set<string>();

    for (const rect of container.querySelectorAll('rect')) {
        const left = CELL / 2 + anchorX + Number(rect.getAttribute('x'));
        const top = anchorY + Number(rect.getAttribute('y'));

        for (
            let row = top;
            row < top + Number(rect.getAttribute('height'));
            row += 1
        ) {
            for (
                let col = left;
                col < left + Number(rect.getAttribute('width'));
                col += 1
            ) {
                covered.add(`${row},${col}`);
            }
        }
    }

    return covered;
}

/** Every (form, animation, frame) there is, which is what a prop must survive. */
function everyFrame(): { form: SpriteForm; hand: readonly [number, number] }[] {
    const all: { form: SpriteForm; hand: readonly [number, number] }[] = [];

    for (const form of FORMS) {
        for (const animation of form.animations) {
            for (
                let frame = 0;
                frame < ANIMATIONS[animation].frames;
                frame += 1
            ) {
                all.push({
                    form,
                    hand: anchorFor(form, 'hand', animation, frame),
                });
            }
        }
    }

    return all;
}

describe('sprite items', () => {
    /**
     * The sole this file measures against is the same row the renderer stands
     * the body on. Two copies of one measurement that nothing compared is how
     * a wrong `form.foot` stayed green through a whole review.
     */
    it('measures the same sole the layout stands the body on', () => {
        for (const form of FORMS) {
            expect(SOLE[form.feature], form.feature).toBe(form.foot);
        }
    });

    it('covers every item type the ladder can name', () => {
        for (const type of ['shoes', 'scarf', 'hat', 'glasses']) {
            expect(SPRITE_ITEMS[type]).toBeDefined();
        }
    });

    /**
     * Rounded corners and strokes are anti-aliased by the browser, which
     * is exactly the soft edge pixel art must not have. Hard rectangles at
     * integer coordinates are indistinguishable from pixels.
     *
     * Both dictionaries: an ability prop is drawn on the same grid a hat is,
     * so the same rule binds it.
     */
    it('draws only hard-edged rectangles on integer coordinates', () => {
        const drawings = [
            ...Object.keys(SPRITE_ITEMS).map((type) => drawItem(type)),
            ...Object.keys(SPRITE_ABILITIES).map((name) => drawAbility(name)),
        ];

        for (const container of drawings) {
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
     * Round 4: a filled lens sat directly on the dark eye pixels and merged
     * into one solid bar across the face — a blindfold, on every frame the
     * eyes would otherwise show through (`blink`, `pet`'s squint, `notice`'s
     * stare). Glasses are a frame around each eye now, not a fill over it.
     *
     * This checks both halves of that, and the second is the one that
     * actually catches a regression to solid fills: the frame must enclose
     * the eye box on all four sides, *and* nothing may cover a single pixel
     * of the box's interior. A test that only checked enclosure would stay
     * green for a solid disc big enough to reach every edge.
     *
     * The eye box is columns 24–28 / 36–40, rows face−1..face+3 on every
     * form (sprites/README.md). `face`'s own anchor is pinned to a literal
     * rather than read live, for the same reason as the lens/shoe column
     * tests elsewhere in this file (review round 3): reading it live would
     * let this test's own expected box drift along with a regression in the
     * anchor table itself.
     */
    it('frames each eye rather than covering it', () => {
        expect(ARMS_FORM.anchors.face).toEqual([-1, 34]);

        const faceX = -1;
        const faceY = 34;
        const boxes = Array.from(
            drawItem('glasses', '#000', ARMS_FORM).querySelectorAll('rect'),
        ).map((rect) => {
            const x = Number(rect.getAttribute('x'));
            const y = Number(rect.getAttribute('y'));
            const width = Number(rect.getAttribute('width'));
            const height = Number(rect.getAttribute('height'));

            return {
                left: CELL / 2 + faceX + x,
                right: CELL / 2 + faceX + x + width - 1,
                top: faceY + y,
                bottom: faceY + y + height - 1,
            };
        });

        const covered = new Set<string>();

        for (const box of boxes) {
            for (let row = box.top; row <= box.bottom; row += 1) {
                for (let col = box.left; col <= box.right; col += 1) {
                    covered.add(`${row},${col}`);
                }
            }
        }

        const eyeRows: [number, number] = [faceY - 1, faceY + 3];
        const eyeColumns: [number, number][] = [
            [24, 28],
            [36, 40],
        ];

        for (const [left, right] of eyeColumns) {
            // Interior: not one eye pixel may be covered.
            for (let row = eyeRows[0]; row <= eyeRows[1]; row += 1) {
                for (let col = left; col <= right; col += 1) {
                    expect(covered.has(`${row},${col}`)).toBe(false);
                }
            }

            // Frame: the 1px border immediately outside the box, on all
            // four sides, must be fully covered — an absent side would
            // leave the eye open on that edge instead of framed.
            for (let col = left - 1; col <= right + 1; col += 1) {
                expect(covered.has(`${eyeRows[0] - 1},${col}`)).toBe(true);
                expect(covered.has(`${eyeRows[1] + 1},${col}`)).toBe(true);
            }

            for (let row = eyeRows[0] - 1; row <= eyeRows[1] + 1; row += 1) {
                expect(covered.has(`${row},${left - 1}`)).toBe(true);
                expect(covered.has(`${row},${right + 1}`)).toBe(true);
            }
        }
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

describe('sprite ability props', () => {
    it('covers every ability the ladder gives Blob something to hold for', () => {
        for (const name of ['read', 'carry']) {
            expect(SPRITE_ABILITIES[name]).toBeDefined();
        }
    });

    /**
     * `hand` is the only anchor no worn item uses, and the only one measured
     * for a prop. Hanging a prop off `head` or `feet` instead would put it
     * over the face or through the floor.
     */
    it('hangs both props off the hand anchor', () => {
        for (const name of Object.keys(SPRITE_ABILITIES)) {
            expect(SPRITE_ABILITIES[name].anchor).toBe('hand');
        }
    });

    /**
     * A prop drawn past the cell edge is clipped by the nested `<svg>` the
     * renderer crops each cell with, so it silently loses a slice rather than
     * looking wrong — which is why this is checked by arithmetic rather than
     * by eye. Every form and every frame, because `hand` differs per form.
     */
    it('keeps every prop inside the cell, on every form and frame', () => {
        for (const name of Object.keys(SPRITE_ABILITIES)) {
            const container = drawAbility(name);

            for (const { form, hand } of everyFrame()) {
                for (const key of coveredBy(container, hand)) {
                    const [row, col] = key.split(',').map(Number);

                    expect(
                        row >= 0 && row < CELL && col >= 0 && col < CELL,
                        `${name} leaves the cell at ${key} on ${form.feature}`,
                    ).toBe(true);
                }
            }
        }
    });

    /**
     * The face and the sprout are the whole of Blob's identity, and a prop
     * that covers either is worse than no prop at all.
     *
     * The face's own columns are 24–40 on every form — the two eye boxes and
     * the mouth between them (sprites/README.md) — and the sprout is
     * everything above the `head` anchor, which is the top of the skull. Both
     * bounds are read per frame, since `face` and `head` both move.
     */
    it('covers neither the face nor the sprout, on any form or frame', () => {
        for (const name of Object.keys(SPRITE_ABILITIES)) {
            const container = drawAbility(name);

            for (const form of FORMS) {
                for (const animation of form.animations) {
                    for (
                        let frame = 0;
                        frame < ANIMATIONS[animation].frames;
                        frame += 1
                    ) {
                        const hand = anchorFor(form, 'hand', animation, frame);
                        const [, faceY] = anchorFor(
                            form,
                            'face',
                            animation,
                            frame,
                        );
                        const [, headY] = anchorFor(
                            form,
                            'head',
                            animation,
                            frame,
                        );

                        for (const key of coveredBy(container, hand)) {
                            const [row, col] = key.split(',').map(Number);
                            const onTheFace =
                                col >= 24 &&
                                col <= 40 &&
                                row >= faceY - 1 &&
                                row <= faceY + 6;

                            expect(
                                onTheFace,
                                `${name} covers the face at ${key} on ${form.feature} ${animation} ${frame}`,
                            ).toBe(false);
                            expect(
                                row > headY,
                                `${name} reaches the sprout at ${key} on ${form.feature} ${animation} ${frame}`,
                            ).toBe(true);
                        }
                    }
                }
            }
        }
    });

    /**
     * A Blob that has earned both draws both, and they must not land on top
     * of one another — the vector renderer stacks them for the same reason.
     * One shape hidden under another is a rung that announced something and
     * changed nothing, which is the failure this whole phase corrects.
     */
    it('never lets the two props occupy the same pixel', () => {
        const book = drawAbility('read');
        const block = drawAbility('carry');

        for (const { form, hand } of everyFrame()) {
            const bookPixels = coveredBy(book, hand);

            expect(bookPixels.size).toBeGreaterThan(0);

            for (const key of coveredBy(block, hand)) {
                expect(
                    bookPixels.has(key),
                    `both props claim ${key} on ${form.feature}`,
                ).toBe(false);
            }
        }
    });
});
