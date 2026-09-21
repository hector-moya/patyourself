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
import nodeDeadfallBare from './scenes/node-deadfall-bare.png';
import nodeDeadfallPlenty from './scenes/node-deadfall-plenty.png';
import nodeDeadfallSome from './scenes/node-deadfall-some.png';
import nodeReedsBare from './scenes/node-reeds-bare.png';
import nodeReedsPlenty from './scenes/node-reeds-plenty.png';
import nodeReedsSome from './scenes/node-reeds-some.png';
import nodeSalvagePlenty from './scenes/node-salvage-plenty.png';
import nodeSalvageSome from './scenes/node-salvage-some.png';
import nodeTrunkBare from './scenes/node-trunk-bare.png';
import nodeTrunkPlenty from './scenes/node-trunk-plenty.png';
import nodeTrunkSome from './scenes/node-trunk-some.png';
import shelterCabin from './scenes/shelter-cabin.png';
import shelterHut from './scenes/shelter-hut.png';
import shelterLeanTo from './scenes/shelter-lean-to.png';

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
 */
export interface NodeSpec {
    /** Matches the key in `config('companion.nodes')`. */
    node: string;
    /**
     * Where the thing STANDS — its base centre, in the room's own coordinates.
     *
     * This was the hotspot's centre while a node was a label, and F3.6 changed
     * it along with the label, the same way `SceneSpec.shelter` changed in
     * F3.5 and for the same reason: foliage is a cell placed on a grid, and a
     * node, like a structure, stands somewhere. The art's top-left derives as
     * `(x − w/2, y − h)` from `NODE_CELLS`.
     */
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
     *
     * Unlike `FoliageSpec.at`, which is a cell's top-left, this is the base
     * centre — where the building *stands*. Foliage is a cell placed on a
     * grid; a structure stands somewhere. The art's top-left derives as
     * `(x − SHELTER_CELL/2, y − SHELTER_CELL)`.
     */
    shelter?: readonly [number, number];
}

/**
 * One cell for all three stages, so the size is a constant rather than
 * per-scene data. If a later scene ever needs a different one, that is when
 * it earns a field on `SceneSpec`.
 */
export const SHELTER_CELL = 48;

const SHELTER_SPRITES: Record<string, string> = {
    'lean-to': shelterLeanTo,
    hut: shelterHut,
    cabin: shelterCabin,
};

/**
 * The sprite for a built stage, or nothing.
 *
 * `Object.hasOwn` rather than a bare lookup: `SHELTER_SPRITES['constructor']`
 * resolves to a function through the prototype chain, and a truthy value here
 * would be passed to `<image href>`. BLOB.md §12 records that `ROOM_OBJECTS`
 * and `SPRITE_ITEMS` still carry exactly that bug.
 */
export function shelterSprite(stage: string): string | undefined {
    return Object.hasOwn(SHELTER_SPRITES, stage) ? SHELTER_SPRITES[stage] : undefined;
}

export { SHELTER_SPRITES };

/**
 * The cell each node's art is drawn on, in the room's own units.
 *
 * Per-node and not square, following `FoliageSpec.cell` rather than
 * `SHELTER_CELL`: the sprites are GENERATED at 48x48 because
 * `create_1_direction_object` forces a square derived from its style image,
 * and then cropped to the art's real bounds before they ship. Four 48-wide
 * cells plus the shelter's 48 need 240 units of a 144-unit room, so square
 * cells were never going to stand in this clearing at once.
 *
 * Every band of one node shares one cell, because all of them were cropped to
 * one box — the union of their bounds. Cropping each to its own would lose
 * registration and the pile would jump sideways as it grew.
 */
export const NODE_CELLS: Record<string, readonly [number, number]> = {
    reeds: [48, 41],
    deadfall: [44, 36],
    trunk: [48, 47],
    salvage: [46, 35],
};

/**
 * Node, then band, to the art.
 *
 * THE HEAP HAS NO `bare`. A node without a skill is a heap rather than the
 * world (BLOB.md §10): it is absent until something places it, nothing
 * restocks it, and `HarvestNode` deletes it once drained — so
 * `CompanionBag::nodes()` never sends one at zero and a `bare` heap is a state
 * the system cannot reach. Drawing one would be art for a state nothing can
 * produce, and BLOB.md §12 records what guarding an unreachable state costs.
 */
const NODE_SPRITES: Record<string, Record<string, string>> = {
    reeds: { bare: nodeReedsBare, some: nodeReedsSome, plenty: nodeReedsPlenty },
    deadfall: { bare: nodeDeadfallBare, some: nodeDeadfallSome, plenty: nodeDeadfallPlenty },
    trunk: { bare: nodeTrunkBare, some: nodeTrunkSome, plenty: nodeTrunkPlenty },
    salvage: { some: nodeSalvageSome, plenty: nodeSalvagePlenty },
};

/**
 * The art for one node at one band, or nothing.
 *
 * `Object.hasOwn` at BOTH levels: each is a record keyed by a string that came
 * from the server, and a bare lookup resolves `'constructor'` to a truthy
 * function through the prototype chain — which would then be handed to
 * `<image href>`. BLOB.md §12 records that `ROOM_OBJECTS` and `SPRITE_ITEMS`
 * still carry that bug and only this file was fixed.
 */
export function nodeSprite(node: string, band: string): string | undefined {
    if (!Object.hasOwn(NODE_SPRITES, node)) {
        return undefined;
    }

    const bands = NODE_SPRITES[node];

    return Object.hasOwn(bands, band) ? bands[band] : undefined;
}

/** The cell a node's art is drawn on, or nothing. */
export function nodeCell(node: string): readonly [number, number] | undefined {
    return Object.hasOwn(NODE_CELLS, node) ? NODE_CELLS[node] : undefined;
}

export { NODE_SPRITES };

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
            // The shelter's 48-wide cell only fits between x=40 and x=48
            // before overflowing the room's right edge at 72, so the shelter
            // and the reeds are stuck sharing that column and can only be
            // separated vertically. At the shelter's new y=34 the two
            // collided at the reeds' old position, [44, 36], so the reeds
            // move down and right to clear it.
            //
            // x=50, not 58: measured on the live page rather than predicted
            // from box arithmetic. Hotspot labels are a fixed 8px font that
            // does not scale with the SVG, so pushed toward the room's right
            // wall the label does not overflow it — it WRAPS to a second
            // line instead, which no box calculation on this branch models
            // because every one of them models width, not reflow. Sweeping
            // x on the rendered page, the label wraps at 58, 56, 54 and 52,
            // and sits on one line from 50 leftward, so 50 is the rightmost
            // value that keeps it whole. y still carries the clearance from
            // the shelter — x never did — so [50, 44] loses nothing there
            // while leaving 41px to the wall instead of 23.
            { node: 'reeds', at: [50, 44] },
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
        // This is the base centre the sprite stands on, not a label's centre
        // — see `SceneSpec.shelter`'s docblock. The art's top-left derives as
        // (x − SHELTER_CELL/2, y − SHELTER_CELL) = (20, −14).
        //
        // x=44, inside a window bounded on both sides by different things:
        //   - Upper bound, from the wall: the cell's right edge must clear
        //     the room's right wall at 72, so x + SHELTER_CELL/2 ≤ 72 gives
        //     x ≤ 48. Nothing else produces this number.
        //   - Lower bound, from Blob — and WHICH Blob matters, because the
        //     two renderers disagree about how wide it is. `blob-renderer.tsx`
        //     declares `BODY.w = 44` for the vector renderer, so its box
        //     spans ±22 and the cell clears it at x ≥ 46. The sprite
        //     renderer — what `config('companion.renderer')` defaults to,
        //     and so what actually ships — draws a visibly narrower
        //     silhouette, measured off a render at roughly ±15, clearing at
        //     x ≥ 39 instead. Any clearance measured against "Blob" has to
        //     say which of the two it used.
        // At x=44 the cell's left edge is 20: it clears the drawn sprite (the
        // one that renders) by 5 units, while overlapping the vector body's
        // declared box by 2 — harmless only because the vector renderer is
        // not what draws. Either way the window between the wall and Blob is
        // narrow, which is why nothing sharing it can be separated from the
        // shelter sideways, and why the reeds, which used to sit at this
        // same x, moved instead; see the `reeds` node above.
        //
        // y=34, not the measured ground line. PNG row 62 is the backdrop's
        // ground line in all four sprites (PNG row = y + 38), which gives
        // y=24 — but rendered there a building reads as standing IN the
        // trees: the measured ground line is the clearing's BACK WALL, not
        // its open floor, so anything planted on it sits among the trunks
        // rather than in the clearing. Four heights were rendered and y=34
        // is the one that actually stands in the clearing.
        shelter: [44, 34],
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
