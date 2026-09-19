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
    /**
     * Where a structure stands, when one has been built. Absent indoors.
     *
     * Placement only, exactly as `nodes` is: WHETHER anything is standing
     * there, and which stage it is, both come from the server.
     */
    shelter?: readonly [number, number];
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
            // Both constraints on y are still correct and y stays at 48.
            //
            // x did not. [-24, 48] was computed against the other labels and
            // never against Blob, and a render this session — the built page
            // viewed, the label dragged in the live DOM, the result
            // photographed — showed it sitting on Blob's left arm: Blob's
            // silhouette spans roughly x −15..15 around the centre, and a
            // box centred at −24 that is ~16 room units half-wide at a 508px
            // stage reaches −7.7, well inside it.
            //
            // −40 solves the two constraints that now bind: the right edge
            // clears Blob's silhouette (≈ −15, with a margin), and the left
            // edge stays inside the room's left wall at −72. That gives a
            // feasible window of roughly −55.7 ≤ x ≤ −33.6 at a 508px stage.
            // −40 sits inside it, clears Blob by about 8 room units (~30px),
            // and keeps a visible 6-unit offset from the deadfall's label at
            // −46 rather than stacking directly under it.
            //
            // The caveat still holds, and is sharper now the window is
            // known: because the label is a fixed 8px font that does not
            // scale with the SVG, its width in ROOM UNITS grows as the stage
            // shrinks. Below roughly a 303px stage the window above is EMPTY
            // — twice the box's half-width exceeds the space between Blob
            // and the wall, so no x clears both constraints at once. That is
            // the label-sizing problem the feature has deliberately left
            // open, not something this coordinate can answer.
            { node: 'trunk', at: [-40, 48] },
            // The heap: the cabin an established record used to be given,
            // in pieces. It stands in the one gap the grass leaves.
            //
            // The arithmetic, because BLOB.md trap 4 is that geometry cannot
            // judge a visual and this coordinate is therefore a STARTING
            // POINT that has to be looked at:
            //   - Hotspot labels are real buttons at a fixed 8px font that
            //     does NOT scale with the svg, so a box is the same pixel
            //     size at every stage width and takes up more ROOM UNITS the
            //     smaller the stage gets.
            //   - "THE HEAP" is 8 characters: roughly 48px of glyphs plus
            //     12px of padding and 2px of border, so about 62px wide and
            //     16px tall.
            //   - The three grass tufts occupy x −70..−38, −26..6 and 36..68
            //     below y=52, which leaves exactly one gap at x 6..36, thirty
            //     units wide. At a 400px stage 62px is 22 room units, so a
            //     box centred at x=21 spans 10..32 and sits comfortably
            //     inside. At a 300px stage the same box is 30 units and
            //     spans 6..36 — exactly the gap, with nothing to spare: the
            //     tightest case, and the reason the label was shortened to
            //     eight characters in the first place.
            //   - y=62 is below Blob's feet (FLOOR is 52) and above the
            //     room's own bottom edge at 76.
            { node: 'salvage', at: [21, 62] },
        ],
        // Back and to the right, against the treeline: a building belongs
        // behind the things you pick up rather than in front of them.
        //
        // "LEAN-TO" is the longest of the three stage labels at 7 characters
        // — about 56px, or 20 room units at a 400px stage — so a box centred
        // at x=44 spans 34..54 and stays inside the room's right edge at 72.
        // That reasoning is still correct and x stays at 44.
        //
        // y did not. [44, -10] was argued only against THE REEDS' label, 46
        // units below at box heights of 6 units at 400px and 8 at 300px —
        // never against the ground. A render this session — the built page
        // viewed, the label dragged in the live DOM, the result photographed
        // — showed it floating in open sky: every other object in the
        // clearing sits at y ∈ {36, 40, 48, 62} in a room whose viewBox spans
        // y −38..76, and −10 alone sat up among the landmark tree's canopy.
        //
        // 22 puts it where the treeline meets the grass at the back of the
        // clearing — still behind the things you pick up, now standing on
        // the ground instead of above it. THE REEDS sits at [44, 36], the
        // same x; the vertical gap between the two label boxes is ~4.5 room
        // units tall at a 508px stage and ~7.7 at 300px, so at y=22 the two
        // clear each other by about 6.3 units even in the tightest case.
        shelter: [44, 22],
    },

    cabin: {
        name: 'cabin',
        // Not a place the record puts Blob any more: the forest is always the
        // world, and the interior is a view of something Blob BUILT. This entry
        // survives because `COMPANION_SCENE=cabin` still needs a name to resolve
        // to, and because `CompanionRoom` reads `base` from whatever scene it was
        // handed. The wall, floor and window are drawn by `CompanionRoom` itself,
        // per stage.
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
