/**
 * Where the pixels are, and where things hang off them.
 *
 * The body sprite is one cell of a sheet. Everything worn on it is placed
 * by an anchor measured from that cell's top-left corner — per form,
 * because a form changes the body, and per frame only where a limb
 * actually carries the accessory.
 *
 * Alignment lives here rather than in the art. That is affordable only
 * because none of the four item types hangs off a hand: heads and necks
 * ride the body mass, and shoes are the single exception.
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

export type AnchorName = 'head' | 'neck' | 'feet' | 'hand';

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
            head: [-1, 11],
            neck: [-1, 37],
            feet: [-1, 51],
            hand: [16, 31],
        },
        // The body breathes by changing height, which carries the head with
        // it even at rest. `feet` stays put throughout — nothing here has
        // legs yet — so it needs no table.
        offsets: {
            idle: {
                head: [
                    [0, 0],
                    [0, -1],
                ],
                neck: [
                    [0, 0],
                    [0, -1],
                ],
            },
            blink: {
                head: [
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
        feature: 'legs',
        sheet: legsSheet,
        animations: ['idle', 'blink'],
        foot: 53,
        anchors: {
            head: [-1, 9],
            neck: [-1, 31],
            feet: [-1, 53],
            hand: [14, 31],
        },
        offsets: {
            idle: {
                head: [
                    [0, 0],
                    [0, 2],
                ],
                neck: [
                    [0, 0],
                    [0, 2],
                ],
            },
            blink: {
                head: [
                    [0, 1],
                    [0, 1],
                ],
                neck: [
                    [0, 1],
                    [0, 1],
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
            head: [-1, 11],
            neck: [-1, 41],
            feet: [-1, 53],
            hand: [18, 32],
        },
        // Measured, not guessed. `shoes` follow the legs through `walk`;
        // `head` and `neck` follow the body's height through any animation
        // that swells or settles, which every animation but `blink` does at
        // this form's frame rate. An animation absent here needs no offsets
        // at all — `blink` holds the body still on purpose, the same reason
        // the SVG renderer does.
        offsets: {
            idle: {
                head: [
                    [0, 0],
                    [0, -2],
                ],
                neck: [
                    [0, 0],
                    [0, -2],
                ],
            },
            walk: {
                head: [
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
                    [0, -2],
                    [0, -1],
                ],
                neck: [
                    [0, -1],
                    [0, -2],
                    [0, -2],
                    [0, -1],
                ],
            },
            jump: {
                head: [
                    [0, 1],
                    [0, 0],
                    [0, -3],
                    [0, -1],
                ],
                neck: [
                    [0, 1],
                    [0, 0],
                    [0, -3],
                    [0, -1],
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
                    [0, 1],
                    [0, 1],
                    [0, 0],
                ],
                neck: [
                    [0, 0],
                    [0, 1],
                    [0, 1],
                    [0, 0],
                ],
            },
            play: {
                head: [
                    [0, 0],
                    [0, -1],
                    [0, -3],
                    [0, -5],
                    [0, -4],
                    [0, -2],
                ],
                neck: [
                    [0, 0],
                    [0, -1],
                    [0, -3],
                    [0, -5],
                    [0, -4],
                    [0, -2],
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
                    [0, 0],
                    [0, -2],
                    [0, -4],
                    [0, -2],
                ],
                neck: [
                    [0, 0],
                    [0, -2],
                    [0, -4],
                    [0, -2],
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
