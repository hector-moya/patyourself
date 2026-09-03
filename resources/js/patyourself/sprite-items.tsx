/**
 * What Blob wears, drawn for the pixel renderer.
 *
 * A separate dictionary from `ITEMS` on purpose. That one draws the vector
 * Blob that ships today, with rounded corners and a stroke that are right
 * there and wrong here — a browser anti-aliases both, and a soft edge is
 * the one thing pixel art cannot have. Editing it in place would change a
 * drawing that is live.
 *
 * Both read the same PALETTE, so a recolour the tail names lands in both
 * without a second palette to keep in step — but this module never imports
 * `PALETTE` itself. `blob-renderer.tsx` imports `SPRITE_ITEMS` from here, so
 * an import running the other way would be a cycle: whichever of the two
 * loads first would read `PALETTE` before its own module body had assigned
 * it. Each item names its default colour by PALETTE *key* instead
 * (`colourKey`), and `blob-renderer.tsx` — where `PALETTE` actually lives —
 * resolves the key to a colour at render time.
 *
 * Coordinates are cell pixels relative to the item's anchor. `head` is the
 * skull's top row and `face` is the eye line — both colour-measured (skin
 * tone, eye-highlight white), not position-measured, after `head` was found
 * to have originally landed on the sprout's own outline instead. `neck`
 * (the widest row) and `feet` (the lowest opaque row) were already correct.
 * See `sprites/README.md` for the measurement itself.
 *
 * Shoes are the one item whose geometry depends on the form: `blob` has no
 * legs, so its foot nubs are wider and set further apart than the narrower
 * feet `legs` and `arms` share. One shared rect pair cannot fit both, so
 * `shoes.render` is the one render function that reads its second argument.
 */
import type { ReactNode } from 'react';

import type { AnchorName, SpriteForm } from '@/patyourself/sprite-layout';

export interface SpriteItemSpec {
    anchor: AnchorName;
    /** Resolved against PALETTE (or blob-renderer.tsx's INK) by the caller. */
    colourKey: string;
    render: (colour: string, form: SpriteForm) => ReactNode;
}

/**
 * Foot centre and width, per form, from the cell's centre line — measured
 * two rows above the sole, where the nub is at its widest rather than
 * tapering into the floor. `blob` has no legs to narrow its stance; `legs`
 * and `arms` grow the same feet, so they share one entry.
 */
const FOOT_RECTS: Record<
    string,
    {
        left: { x: number; width: number };
        right: { x: number; width: number };
    }
> = {
    blob: { left: { x: -15, width: 12 }, right: { x: 3, width: 11 } },
    legs: { left: { x: -11, width: 9 }, right: { x: 3, width: 9 } },
    arms: { left: { x: -11, width: 9 }, right: { x: 3, width: 9 } },
};

export const SPRITE_ITEMS: Record<string, SpriteItemSpec> = {
    shoes: {
        anchor: 'feet',
        colourKey: 'slate',
        render: (colour, form) => {
            const feet = FOOT_RECTS[form.feature] ?? FOOT_RECTS.legs;

            return (
                <>
                    <rect
                        x={feet.left.x}
                        y={-2}
                        width={feet.left.width}
                        height={3}
                        fill={colour}
                    />
                    <rect
                        x={feet.right.x}
                        y={-2}
                        width={feet.right.width}
                        height={3}
                        fill={colour}
                    />
                </>
            );
        },
    },
    scarf: {
        anchor: 'neck',
        colourKey: 'rust',
        // `neck` sits at the widest row, which on this round body is cheek
        // height — right on the mouth. Drawn a few rows below the anchor
        // instead of on it, so the band clears the mouth and reads as a
        // collar rather than a mask.
        render: (colour) => (
            <>
                <rect x={-9} y={5} width={18} height={3} fill={colour} />
                <rect x={5} y={8} width={3} height={6} fill={colour} />
            </>
        ),
    },
    hat: {
        anchor: 'head',
        colourKey: 'slate',
        render: (colour) => (
            <>
                <rect x={-5} y={-6} width={10} height={5} fill={colour} />
                <rect x={-8} y={-1} width={16} height={2} fill={colour} />
            </>
        ),
    },
    glasses: {
        anchor: 'face',
        colourKey: 'ink',
        render: (colour) => (
            <>
                <rect x={-7} y={-2} width={5} height={4} fill={colour} />
                <rect x={2} y={-2} width={5} height={4} fill={colour} />
                <rect x={-2} y={-1} width={4} height={1} fill={colour} />
            </>
        ),
    },
};
