/**
 * Where the pixels are, and where things hang off them.
 *
 * The body sprite is one cell of a sheet. Everything drawn on it is placed
 * by an anchor — per form, because a form changes the body, and per frame
 * only where a limb actually carries the accessory.
 *
 * **An anchor's two components are measured from different edges.** `y` is
 * rows down from the cell's top edge; `x` is columns from the cell's *centre*
 * column, signed, which is why every consumer adds `CELL / 2` before drawing.
 * Reading `x` as an offset from the left edge is what put an accessory 32px
 * off the body earlier in this branch, so the difference is stated here
 * rather than left to be rediscovered.
 *
 * Alignment lives here rather than in the art. That is affordable only
 * because none of the four item types hangs off a hand: heads and necks
 * ride the body mass, and shoes are the single exception. `hand` carries the
 * two ability props, which are simple shapes at the body's side rather than
 * something gripped, so it is measured once per form and needs no table.
 *
 * Every number below is measured off the art in `sprites/README.md`, not
 * guessed — see that file for how, and for the reasoning behind which
 * frames of `idle`/`blink` were kept.
 */
import type { AnimationName } from '@/patyourself/companion-animations';
import { ANIMATIONS } from '@/patyourself/companion-animations';

import armsSheet from './sprites/humus-arms.png';
import blobSheet from './sprites/humus-blob.png';
import legsSheet from './sprites/humus-legs.png';

/** Every cell is this square, on every sheet. */
export const CELL = 64;

export type AnchorName = 'head' | 'face' | 'neck' | 'feet' | 'hand';

type Offsets = Partial<
    Record<
        AnimationName,
        Partial<Record<AnchorName, readonly (readonly [number, number])[]>>
    >
>;

export interface SpriteForm {
    /** The `features` entry that brings this form about. */
    feature: string;
    sheet: string;
    /** One row per entry, in this order. */
    animations: readonly AnimationName[];
    /** The cell row the character's feet rest on. */
    foot: number;
    anchors: Readonly<Record<AnchorName, readonly [number, number]>>;
    /** Per-frame anchor overrides, for the anchors that follow a limb. */
    offsets?: Offsets;
}

/**
 * Earliest first. A record earns them in this order and never goes back:
 * a rounded mass, then legs, then arms.
 */
export const FORMS: readonly SpriteForm[] = [
    {
        feature: 'blob',
        sheet: blobSheet,
        animations: ['idle', 'blink'],
        foot: 51,
        anchors: {
            head: [-1, 21],
            face: [-1, 34],
            neck: [-1, 43],
            feet: [-1, 51],
            hand: [16, 36],
        },
        // The body breathes by changing height, which carries the head with
        // it even at rest. `feet` stays put throughout — nothing here has
        // legs yet — so it needs no table. `blink` needs none either: this
        // form's measured blink shows no body movement worth a table entry.
        // `neck` rides the skull now (it is `face`'s own row, `face + 9`),
        // so its offset here is `face`'s delta, not a separate measurement.
        offsets: {
            idle: {
                head: [
                    [0, 0],
                    [0, -1],
                ],
                face: [
                    [0, 0],
                    [0, -2],
                ],
                neck: [
                    [0, 0],
                    [0, -2],
                ],
            },
        },
    },
    {
        feature: 'legs',
        sheet: legsSheet,
        animations: ['idle', 'blink'],
        foot: 53,
        anchors: {
            head: [-1, 19],
            face: [-1, 32],
            neck: [-1, 41],
            feet: [-1, 53],
            hand: [14, 36],
        },
        offsets: {
            idle: {
                head: [
                    [0, 0],
                    [0, 1],
                ],
                face: [
                    [0, 0],
                    [0, 2],
                ],
                // `neck` rides the skull (it is `face`'s own row, `face +
                // 9`), so its delta is `face`'s, not a separate reading.
                neck: [
                    [0, 0],
                    [0, 2],
                ],
            },
            // The closed frame of blink is where the eye line cannot be
            // measured, so `face` takes `head`'s own delta rather than a
            // separate one — a derivation, not a second guess. `neck`
            // follows `face` here too, for the same reason as `idle` above.
            blink: {
                head: [
                    [0, 1],
                    [0, 0],
                ],
                face: [
                    [0, 1],
                    [0, 0],
                ],
                neck: [
                    [0, 1],
                    [0, 0],
                ],
            },
        },
    },
    {
        feature: 'arms',
        sheet: armsSheet,
        animations: [
            'idle',
            'blink',
            'walk',
            'wave',
            'jump',
            'pet',
            'play',
            'notice',
        ],
        foot: 53,
        anchors: {
            head: [-1, 21],
            face: [-1, 34],
            neck: [-1, 43],
            feet: [-1, 53],
            hand: [18, 37],
        },
        // Measured, not guessed. `shoes` follow the legs through the
        // animations that step; `head`, `face` and `neck` all follow the
        // skull through any animation that swells or settles — `neck`'s own
        // delta is `face`'s, since `neck` is `face + 9` rather than an
        // independent reading (see sprites/README.md for why "the widest
        // row" was the wrong thing to measure here). An anchor absent from a
        // row does not move during it; an animation absent entirely needs no
        // offsets at all — this form's blink is one of those: nothing here
        // has a measured delta for it, the skull holding still the same way
        // `blob`'s own blink does.
        offsets: {
            idle: {
                head: [
                    [0, 0],
                    [0, -2],
                ],
                face: [
                    [0, 0],
                    [0, -3],
                ],
                neck: [
                    [0, 0],
                    [0, -3],
                ],
            },
            walk: {
                head: [
                    [0, -2],
                    [0, -3],
                    [0, -3],
                    [0, -2],
                ],
                face: [
                    [0, -2],
                    [0, -3],
                    [0, -3],
                    [0, -2],
                ],
                neck: [
                    [0, -2],
                    [0, -3],
                    [0, -3],
                    [0, -2],
                ],
                feet: [
                    [0, 0],
                    [0, 0],
                    [0, -1],
                    [0, 0],
                ],
            },
            wave: {
                head: [
                    [0, -1],
                    [0, -2],
                    [0, -3],
                    [0, -2],
                ],
                face: [
                    [0, -1],
                    [0, -3],
                    [0, -3],
                    [0, -2],
                ],
                neck: [
                    [0, -1],
                    [0, -3],
                    [0, -3],
                    [0, -2],
                ],
            },
            jump: {
                head: [
                    [0, 0],
                    [0, -3],
                    [0, -6],
                    [0, -4],
                ],
                face: [
                    [0, 2],
                    [0, -1],
                    [0, -6],
                    [0, -4],
                ],
                neck: [
                    [0, 2],
                    [0, -1],
                    [0, -6],
                    [0, -4],
                ],
                feet: [
                    [0, 0],
                    [0, -1],
                    [0, -4],
                    [0, -1],
                ],
            },
            pet: {
                head: [
                    [0, 0],
                    [0, 0],
                    [0, 0],
                    [0, -1],
                ],
                face: [
                    [0, 0],
                    [0, 0],
                    [0, 0],
                    [0, -1],
                ],
                neck: [
                    [0, 0],
                    [0, 0],
                    [0, 0],
                    [0, -1],
                ],
            },
            play: {
                head: [
                    [0, -1],
                    [0, -3],
                    [0, -5],
                    [0, -6],
                    [0, -4],
                    [0, -2],
                ],
                face: [
                    [0, -1],
                    [0, -3],
                    [0, -5],
                    [0, -7],
                    [0, -5],
                    [0, -3],
                ],
                neck: [
                    [0, -1],
                    [0, -3],
                    [0, -5],
                    [0, -7],
                    [0, -5],
                    [0, -3],
                ],
                feet: [
                    [0, 0],
                    [0, 0],
                    [0, -2],
                    [0, -5],
                    [0, -2],
                    [0, 0],
                ],
            },
            notice: {
                head: [
                    [0, -2],
                    [0, -6],
                    [0, -4],
                    [0, -2],
                ],
                face: [
                    [0, -3],
                    [0, -8],
                    [0, -6],
                    [0, -4],
                ],
                neck: [
                    [0, -3],
                    [0, -8],
                    [0, -6],
                    [0, -4],
                ],
                feet: [
                    [0, 0],
                    [0, -3],
                    [0, -4],
                    [0, -1],
                ],
            },
        },
    },
];

/** How wide a form's sheet is, in cells. Derived, so it cannot drift. */
export function columnsOf(form: SpriteForm): number {
    return Math.max(...form.animations.map((name) => ANIMATIONS[name].frames));
}

/**
 * The latest form this record has earned.
 *
 * Walked backwards over `FORMS` rather than forwards over `features`: the
 * order features arrive in belongs to the resolver, and leaning on it here
 * would make the drawing depend on something nobody promised.
 */
export function formFor(features: readonly string[]): SpriteForm {
    for (let index = FORMS.length - 1; index > 0; index -= 1) {
        if (features.includes(FORMS[index].feature)) {
            return FORMS[index];
        }
    }

    return FORMS[0];
}

/** Which row an animation sits on, or null when this form has no such row. */
export function rowFor(
    form: SpriteForm,
    animation: AnimationName,
): number | null {
    const row = form.animations.indexOf(animation);

    return row === -1 ? null : row;
}

/** Where an accessory hangs, for this form on this frame. */
export function anchorFor(
    form: SpriteForm,
    anchor: AnchorName,
    animation: AnimationName,
    frame: number,
): readonly [number, number] {
    const base = form.anchors[anchor];
    const offset = form.offsets?.[animation]?.[anchor]?.[frame];

    if (offset === undefined) {
        return base;
    }

    return [base[0] + offset[0], base[1] + offset[1]];
}
