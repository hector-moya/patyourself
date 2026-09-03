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
 * without a second palette to keep in step. That import runs both ways —
 * this module reads PALETTE from blob-renderer.tsx, and blob-renderer.tsx
 * reads SPRITE_ITEMS back from this one — so the default `colour` below is
 * a getter rather than a plain value. Whichever of the two loads first
 * would otherwise read PALETTE before its own module body has assigned it;
 * a getter only evaluates PALETTE.xxx once something actually asks for the
 * colour, by which time both modules have finished loading.
 *
 * Coordinates are cell pixels relative to the item's anchor, measured
 * against the actual sprite art rather than against the anchor's name.
 * `head`, in particular, sits at the top of the sprout rather than the top
 * of the skull — first-non-green-pixel finds the leaf tip's own outline,
 * which is black — so hat and glasses geometry is offset well past what
 * the anchor's name suggests: the skin starts ~10px below `head` and the
 * eye line ~23px below it, the same on every form. `neck` needs no such
 * correction — it is measured as the widest row and lands there exactly —
 * but sitting a scarf right on it covers the mouth, so the band is drawn a
 * few rows below the anchor instead of on it.
 */
import type { ReactNode } from 'react';

import { PALETTE } from '@/patyourself/blob-renderer';
import type { AnchorName } from '@/patyourself/sprite-layout';

export interface SpriteItemSpec {
    anchor: AnchorName;
    /** The item's own colour when the unlock names no variant. */
    colour: string;
    render: (colour: string) => ReactNode;
}

export const SPRITE_ITEMS: Record<string, SpriteItemSpec> = {
    shoes: {
        anchor: 'feet',
        get colour() {
            return PALETTE.slate;
        },
        render: (colour) => (
            <>
                <rect x={-7} y={-2} width={5} height={3} fill={colour} />
                <rect x={2} y={-2} width={5} height={3} fill={colour} />
            </>
        ),
    },
    scarf: {
        anchor: 'neck',
        get colour() {
            return PALETTE.rust;
        },
        render: (colour) => (
            <>
                <rect x={-9} y={5} width={18} height={3} fill={colour} />
                <rect x={5} y={8} width={3} height={6} fill={colour} />
            </>
        ),
    },
    hat: {
        anchor: 'head',
        get colour() {
            return PALETTE.slate;
        },
        render: (colour) => (
            <>
                <rect x={-5} y={3} width={10} height={5} fill={colour} />
                <rect x={-8} y={8} width={16} height={2} fill={colour} />
            </>
        ),
    },
    glasses: {
        anchor: 'head',
        colour: '#2A2622',
        render: (colour) => (
            <>
                <rect x={-7} y={21} width={5} height={4} fill={colour} />
                <rect x={2} y={21} width={5} height={4} fill={colour} />
                <rect x={-2} y={22} width={4} height={1} fill={colour} />
            </>
        ),
    },
};
