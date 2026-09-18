/**
 * Where Blob is drawn: the two places E1 ships, and the art each one needs.
 *
 * `CompanionRoom` asks for one by name and gets back a backdrop per part of
 * day plus a flat colour to fall back on. The cabin's own wall, floor and
 * window are not modelled as data here — they are that scene's own drawing,
 * reached in `companion-room.tsx` by checking the name, because pushing four
 * flat rectangles and a furniture dictionary through a generic shape buys
 * nothing a forest backdrop needs.
 *
 * PNGs are imported as modules rather than referenced by path, so Vite hashes
 * them and a deploy cache-busts instead of serving a stale forest.
 */
import type { AnimationName } from '@/patyourself/companion-animations';

import foliageGrass from './scenes/foliage-grass.png';
import foliageTree from './scenes/foliage-tree.png';
import forestDay from './scenes/forest-day.png';
import forestDusk from './scenes/forest-dusk.png';
import forestNight from './scenes/forest-night.png';
import forestSunrise from './scenes/forest-sunrise.png';

/**
 * A layer of moving foliage over a backdrop: one sheet, one row, uniform
 * cells, read by the clock that already draws Blob.
 *
 * Where every number below came from — measured off the art or derived from
 * the room's own grid — is written up in `scenes/README.md`.
 */
export interface FoliageSpec {
    sheet: string;
    /**
     * Width then height. Neither sheet is square, and squaring them to carry
     * one number would push the tree's cell four units past the room's left
     * edge to leave the art where it was placed — an offset every later reader
     * would have to work out again.
     */
    cell: readonly [number, number];
    /** The cell's top-left corner, in the room's own coordinates. */
    at: readonly [number, number];
    animation: AnimationName;
    /**
     * Frames to add before reading the sheet, defaulting to none.
     *
     * The clock derives its frame from the absolute timestamp precisely so
     * that two Blobs cannot drift apart, which leaves every subscriber to one
     * animation in step by design. Three tufts off one sheet moving together
     * is the metronome this whole layer exists to avoid, and an offset is how
     * they differ without a second loop or a second sheet.
     */
    phase?: number;
}

/**
 * Where a node stands in a scene, in the room's own coordinates.
 *
 * Placement only. WHICH nodes exist, what they are called and what is standing
 * at them all come from the server — this file knows where the reeds are, not
 * that there are reeds.
 *
 * Positions are fixed for the same reason the room objects' are: a clearing
 * that rearranges itself between visits stops being a place.
 *
 * F1 SHIPS THESE AS LABELLED HOTSPOTS RATHER THAN SPRITES, which is the
 * treatment the layout was signed off on. `docs/BLOB.md` §7 is clear that art
 * is the slowest and least predictable part of this feature, and nothing about
 * the mechanism needs a sprite to work — F2 can draw over these without
 * touching a line of it.
 */
export interface NodeSpec {
    /** Matches the key in `config('companion.nodes')`. */
    node: string;
    /** The hotspot's centre, in the room's own coordinates. */
    at: readonly [number, number];
}

export interface SceneSpec {
    name: string;
    /** One backdrop per part of day config knows. A missing part falls back to `base`. */
    backdrops: Record<string, string>;
    /**
     * The flat colour behind the backdrop, so a PNG that fails to load
     * leaves this behind it rather than leaving Blob standing on nothing.
     */
    base: string;
    foliage: readonly FoliageSpec[];
    /** What can be gathered from here. Empty indoors. */
    nodes: readonly NodeSpec[];
}

/**
 * `forest` is declared first: an unknown scene name falls back to whichever
 * entry is first here, the same contract item types, room objects and
 * animations already follow.
 */
export const SCENES: Record<string, SceneSpec> = {
    forest: {
        name: 'forest',
        backdrops: {
            sunrise: forestSunrise,
            day: forestDay,
            dusk: forestDusk,
            night: forestNight,
        },
        base: '#548043',
        // One tree at the clearing's edge, not a row of them: the backdrops
        // were generated four times over to get a clearing rather than a
        // corridor, and foreground trees would close it again. The grass is
        // what carries the near motion instead.
        foliage: [
            {
                sheet: foliageTree,
                cell: [48, 64],
                at: [-68, -32],
                animation: 'sway',
            },
            {
                sheet: foliageGrass,
                cell: [32, 24],
                at: [-70, 52],
                animation: 'rustle',
            },
            {
                sheet: foliageGrass,
                cell: [32, 24],
                at: [-26, 52],
                animation: 'rustle',
                phase: 3,
            },
            {
                sheet: foliageGrass,
                cell: [32, 24],
                at: [36, 52],
                animation: 'rustle',
                phase: 5,
            },
        ],
        // The deadfall sits under the tree, whose cell ends at y=32, so this
        // clears its trunk. The reeds sit off to the right, away from both the
        // tree and Blob's own footprint at the centre. Both are clear of the
        // grass line at y=52 so a hotspot never lands on a moving tuft.
        nodes: [
            { node: 'deadfall', at: [-46, 40] },
            { node: 'reeds', at: [44, 36] },
            // Low and near, between the tree's foot and the centre, so it
            // reads as foreground without standing where Blob does.
            //
            // y=48 clears TWO constraints, not one — the previous y=44 only
            // ever recorded the first of these, which is exactly how it
            // collided with the second:
            //   1. Above the grass line at y=52, same as the other two, so
            //      the hotspot never lands on a moving tuft.
            //   2. Clear of the deadfall's label box. Hotspot labels are real
            //      buttons at a fixed 8px font that does not scale with the
            //      SVG, so at [-24, 44] the trunk's "THE FALLEN TRUNK" box
            //      overlapped the deadfall's "THE FALLEN BRANCHES" box by
            //      roughly 31x2.4px on desktop (up to 64x8px on a 390px
            //      phone) and DOM order let the trunk paint over the
            //      deadfall's hit target.
            // This coordinate is computed from the two boxes' measured
            // dimensions, not eyeballed against a render. Below roughly a
            // 300px stage no y value clears both constraints at once — the
            // labels are simply larger than the room at that scale, which is
            // a label-sizing question and not this coordinate's to answer.
            { node: 'trunk', at: [-24, 48] },
        ],
    },

    cabin: {
        name: 'cabin',
        // No photographed backdrop: the interior is still drawn by
        // CompanionRoom itself — wall, floor, window, ROOM_OBJECTS — exactly
        // as it always has been. `base` exists so this scene still satisfies
        // the same shape as one that does have art.
        backdrops: {},
        base: '#EFE6D6',
        foliage: [],
        // Nothing grows indoors. The clearing is outside, and the cabin is
        // where Blob takes what it gathered.
        nodes: [],
    },
};

/**
 * Naming a scene must never be able to break the screen — the same rule item
 * types, room objects and animations already follow. An unrecognised name
 * falls back to the first scene rather than throwing.
 *
 * `Object.hasOwn` rather than `SCENES[name] ?? …`: a plain object's lookup
 * walks the prototype chain, so `name` values like `'constructor'` or
 * `'toString'` resolve to an inherited `Object` value that is truthy and
 * therefore never triggers the `??` fallback — the lookup then fails further
 * down where the caller reads `.backdrops` off what it thinks is a
 * `SceneSpec`. Checking the key is the record's own, not inherited, closes
 * that off.
 */
export function sceneFor(name: string): SceneSpec {
    return Object.hasOwn(SCENES, name)
        ? SCENES[name]
        : Object.values(SCENES)[0];
}
