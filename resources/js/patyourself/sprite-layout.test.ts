import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import type { AnimationName } from './companion-animations';
import {
    CELL,
    FORMS,
    anchorFor,
    columnsOf,
    formFor,
    rowFor,
} from './sprite-layout';

describe('sprite layout', () => {
    it('gives the fullest form a row for every animation the clock can play', () => {
        const fullest = FORMS[FORMS.length - 1];

        for (const name of Object.keys(ANIMATIONS) as AnimationName[]) {
            expect(fullest.animations).toContain(name);
        }
    });

    it('sizes each sheet by the widest animation it carries', () => {
        for (const form of FORMS) {
            const widest = Math.max(
                ...form.animations.map((name) => ANIMATIONS[name].frames),
            );

            expect(columnsOf(form)).toBe(widest);
        }
    });

    it('picks the latest form the record has earned', () => {
        expect(formFor(['blob']).feature).toBe('blob');
        expect(formFor(['blob', 'legs']).feature).toBe('legs');
        expect(formFor(['blob', 'legs', 'arms']).feature).toBe('arms');
    });

    /**
     * The resolver's ordering is its own business. The renderer must not
     * inherit a promise about it that nobody made.
     */
    it('ignores the order features happen to arrive in', () => {
        expect(formFor(['arms', 'blob', 'legs']).feature).toBe('arms');
    });

    it('falls back to the earliest form when it knows none of the features', () => {
        expect(formFor([]).feature).toBe(FORMS[0].feature);
        expect(formFor(['wings']).feature).toBe(FORMS[0].feature);
    });

    it('reports no row for an animation a form does not carry', () => {
        const earliest = FORMS[0];

        expect(rowFor(earliest, 'idle')).toBe(0);
        expect(rowFor(earliest, 'play')).toBeNull();
    });

    it('returns the form anchor when no offset is declared', () => {
        const form = FORMS[FORMS.length - 1];

        expect(anchorFor(form, 'head', 'idle', 0)).toEqual(form.anchors.head);
    });

    /**
     * Shoes are the one accessory that follows a limb, and only while
     * walking. A frame with no entry falls back to the base anchor rather
     * than to the origin — a missing number must not drop an accessory to
     * the top-left corner of the cell.
     *
     * Frame 2 is the mid-stride frame: the measured walk table only moves
     * the feet on the frame a real step is at its highest (README, arms/walk,
     * where the feet offsets are `[0, 0, -1, 0]` — one row up on frame 2),
     * matching the SVG renderer's own note that the bob rides on mid-step
     * frames. Frame 0 is measured flat, so it cannot be the frame this test
     * reaches for.
     */
    it('applies a walk offset to the feet and falls back past the table', () => {
        const form = FORMS[FORMS.length - 1];
        const base = form.anchors.feet;

        expect(anchorFor(form, 'feet', 'walk', 2)).not.toEqual(base);
        expect(anchorFor(form, 'feet', 'walk', 99)).toEqual(base);
        expect(anchorFor(form, 'feet', 'idle', 0)).toEqual(base);
    });

    it('draws every cell from the same square grid', () => {
        expect(CELL).toBe(64);
    });

    /**
     * `head` and `face` ride the same skull but do not travel by the same
     * amount on every frame — the body squashes and settles at a different
     * rate than the eye line does. `face` exists as its own anchor exactly
     * because of this: an item hung off `head` instead (as glasses briefly
     * were, in review round 1) drifts onto the forehead during `jump` and
     * onto the mouth during `notice`, both reproduced here.
     *
     * Compares how far each anchor has moved from its own base position,
     * not the two anchors' absolute positions — those differ by design
     * (the eye line sits below the skull's top row on every frame), so
     * comparing them directly would pass even if the two moved in lockstep.
     */
    it('moves the face anchor by a different amount than the head anchor', () => {
        const arms = FORMS[FORMS.length - 1];
        const deltaOf = (anchor: 'head' | 'face', frame: number) =>
            anchorFor(arms, anchor, 'jump', frame)[1] - arms.anchors[anchor][1];

        expect(deltaOf('face', 1)).not.toBe(deltaOf('head', 1));

        const noticeDeltaOf = (anchor: 'head' | 'face', frame: number) =>
            anchorFor(arms, anchor, 'notice', frame)[1] -
            arms.anchors[anchor][1];

        expect(noticeDeltaOf('face', 1)).not.toBe(noticeDeltaOf('head', 1));
    });

    /**
     * `neck`'s base position, not just its offsets — `face + 9` puts a
     * collar three rows below the mouth (itself `face + 6`, sprites/
     * README.md) on every form, in place of "the widest opaque row", which
     * lands anywhere from the shoulders to the arms depending on the form.
     */
    it('bases the neck anchor 9 rows below the face anchor on every form', () => {
        for (const form of FORMS) {
            expect(form.anchors.neck[1]).toBe(form.anchors.face[1] + 9);
        }
    });

    /**
     * `neck` = `face + 9` since review round 2. It was previously "the
     * widest opaque row", blessed as unchanged because its numbers hadn't
     * moved — but on a creature with no neck the widest row is the
     * shoulders, the top of the head, or the raised arm mid-`wave`
     * depending on the form and the frame, none of which a scarf should
     * track. `neck` now simply rides `face`, so every animation's `neck`
     * delta must equal that animation's `face` delta, for every frame of
     * every form — not just the form with the richest table, which left
     * `blob`'s and `legs`' own tables unguarded (review round 3).
     */
    it('moves the neck anchor exactly as the face anchor moves, not independently', () => {
        for (const form of FORMS) {
            for (const animation of form.animations) {
                for (
                    let frame = 0;
                    frame < ANIMATIONS[animation].frames;
                    frame += 1
                ) {
                    const faceDelta =
                        anchorFor(form, 'face', animation, frame)[1] -
                        form.anchors.face[1];
                    const neckDelta =
                        anchorFor(form, 'neck', animation, frame)[1] -
                        form.anchors.neck[1];

                    expect(neckDelta).toBe(faceDelta);
                }
            }
        }
    });

    /**
     * The anchors and foot rows are measured off the art in Task 1 and
     * hand-copied here, which is exactly the kind of step that ships with
     * its placeholder zeros still in it. Nothing sits at the top edge of a
     * cell, and no character's feet rest on row zero.
     *
     * The row is the component checked, because it is the only one that can
     * be a placeholder. Every anchor's `x` is measured from the centre column
     * and lands off it, so `x !== 0 || y !== 0` — what this asserted before —
     * held for any `y` at all, including the literal `[-1, 0]` the docblock
     * warns about.
     */
    it('carries measured constants rather than placeholder zeros', () => {
        for (const form of FORMS) {
            expect(form.foot).toBeGreaterThan(0);

            for (const [name, [, y]] of Object.entries(form.anchors)) {
                expect(
                    y,
                    `${form.feature}.${name} sits on row zero`,
                ).toBeGreaterThan(0);
            }
        }
    });
});
