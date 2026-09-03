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
 * to have originally landed on the sprout's own outline instead. `neck` is
 * `face + 9` for the same reason: "the widest row" sounded like a fine way
 * to find a throat, but on a creature with no neck the widest row is the
 * shoulders on one form and the raised arm mid-`wave` on another, so it
 * rode the body's own bulk rather than anything worn could sensibly track.
 * `feet` (the lowest opaque row) was the one already correct as measured.
 * See `sprites/README.md` for the measurement itself.
 *
 * Shoes are the one item whose geometry depends on the form: `blob` has no
 * legs, so its foot nubs are wider and set further apart than the narrower
 * feet `legs` and `arms` share. One shared rect pair cannot fit both, so
 * `shoes.render` is the one render function that reads its second argument.
 */
import type { ReactNode } from 'react';

import type { AnchorName, SpriteForm } from '@/patyourself/sprite-layout';

/**
 * PALETTE's own keys, kept here as a literal union rather than imported —
 * importing the values themselves is the cycle this module exists to avoid
 * (see above). Keeping the *names* as a type costs nothing at runtime and
 * turns an unknown key into a compile error instead of a raw string
 * reaching the DOM as a CSS `fill`, which `string` could not catch.
 */
export type PaletteKey =
    | 'slate'
    | 'rust'
    | 'coral'
    | 'moss'
    | 'amber'
    | 'plum'
    | 'sand';

export interface SpriteItemSpec {
    anchor: AnchorName;
    /**
     * Resolved against PALETTE by blob-renderer.tsx, where PALETTE lives —
     * `'ink'` is the one key that isn't a PALETTE entry at all, resolved
     * against that module's own ink constant instead.
     */
    colourKey: PaletteKey | 'ink';
    render: (colour: string, form: SpriteForm) => ReactNode;
}

/**
 * Foot centre and width, per form, measured from the cell's **centre
 * line** (column 32) — two rows above the sole, where the nub is at its
 * widest rather than tapering into the floor. `blob` has no legs to narrow
 * its stance; `legs` and `arms` grow the same feet, so they share one
 * entry.
 *
 * These are centre-line measurements, not anchor-relative ones: `render`
 * subtracts the `feet` anchor's own `x` (−1, not 0 — the anchor sits one
 * column left of centre) before using them, rather than baking that
 * correction into the constants here. That way a later form whose centre
 * isn't −1 still lands correctly without this table changing at all.
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
            const [anchorX] = form.anchors.feet;

            return (
                <>
                    <rect
                        x={feet.left.x - anchorX}
                        y={-2}
                        width={feet.left.width}
                        height={3}
                        fill={colour}
                    />
                    <rect
                        x={feet.right.x - anchorX}
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
        // `neck` is measured as `face + 9` — three rows below the mouth on
        // every form (sprites/README.md) — so the anchor itself is already
        // where a collar belongs. No compensating offset needed here; that
        // was only ever standing in for `neck` measuring the widest row
        // instead, which put it anywhere from the throat to the arms
        // depending on the form.
        render: (colour) => (
            <>
                <rect x={-9} y={0} width={18} height={3} fill={colour} />
                <rect x={5} y={3} width={3} height={6} fill={colour} />
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
        // A frame around each eye, not a fill over it — a solid lens sat
        // directly on the dark eye pixels and merged into one black bar,
        // putting Blob in a blindfold on every frame (blink, pet's squint,
        // notice's stare all vanished). The SVG renderer draws glasses as
        // `fill="none"` circles with a stroke for exactly this reason; a
        // sprite can't stroke without anti-aliasing, so each lens is four
        // 1px rects instead.
        //
        // The eye box is columns 24–28 / 36–40, rows face−1..face+3 on
        // every form (measured against the sheets); `face.x` is −1, so this
        // layer's column 0 is column 31. Each 7×7 frame sits with a 1px
        // border around that 5×5 box, leaving all 25 eye pixels open. The
        // bridge fills the gap between the two frames' inner borders.
        render: (colour) => (
            <>
                {/* left lens */}
                <rect x={-8} y={-2} width={7} height={1} fill={colour} />
                <rect x={-8} y={4} width={7} height={1} fill={colour} />
                <rect x={-8} y={-1} width={1} height={5} fill={colour} />
                <rect x={-2} y={-1} width={1} height={5} fill={colour} />
                {/* right lens */}
                <rect x={4} y={-2} width={7} height={1} fill={colour} />
                <rect x={4} y={4} width={7} height={1} fill={colour} />
                <rect x={4} y={-1} width={1} height={5} fill={colour} />
                <rect x={10} y={-1} width={1} height={5} fill={colour} />
                {/* bridge */}
                <rect x={-1} y={1} width={5} height={1} fill={colour} />
            </>
        ),
    },
};
