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
import chestPng from './scenes/node-chest.png';
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
    /**
     * Where the chest stands, when one has been built. Absent indoors.
     *
     * Placement only, exactly as `nodes` and `shelter` are: WHETHER one is
     * standing comes from the server's `bag.stash.standing`, never from here.
     *
     * Like `shelter` and unlike `FoliageSpec.at`, this is the BASE CENTRE —
     * where the chest stands, not a cell's top-left. The art's top-left
     * derives as `(x − CHEST_CELL[0] / 2, y − CHEST_CELL[1])`.
     */
    chest?: readonly [number, number];
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
    return Object.hasOwn(SHELTER_SPRITES, stage)
        ? SHELTER_SPRITES[stage]
        : undefined;
}

export { SHELTER_SPRITES };

/**
 * The chest's cell, MEASURED off the cropped art rather than chosen.
 *
 * `create_1_direction_object` forces a 48x48 square, and the crop to the
 * art's own bounding box — nine transparent rows above, six below — is what
 * sets this to 34x33 rather than 48x48; `scenes/README.md` "The chest"
 * records the measurement. Five objects cannot stand apart in a 144-unit
 * room, so this number is part of what the placement render had to fit.
 */
export const CHEST_CELL: readonly [number, number] = [34, 33];

/** The chest's one sprite. It has no bands: a chest is a chest, and what is in it is in the bag. */
export function chestSprite(): string {
    return chestPng;
}

/**
 * Sorts anything with a base centre by that base y, ascending.
 *
 * F3.6's rule for the clearing is that nearer things sit lower and overlap
 * further things, so painting in ascending base-y order puts the nearer
 * thing last — on top of whatever it overlaps. `SCENES.forest.nodes` is
 * already authored in this order (`scenes.test.ts` pins that), so this is a
 * no-op for the four nodes that ship today; it exists so the chest can join
 * the same order rather than being drawn at a hand-picked array index, and
 * so BLOB.md §12's observation — that array order is paint order with
 * nothing enforcing it — stops being true.
 *
 * Returns a fresh array. `Array.prototype.sort` mutates its receiver, and a
 * caller's own list should never come back changed under it.
 */
export function paintOrder<T extends { at: readonly [number, number] }>(
    items: readonly T[],
): T[] {
    return [...items].sort((a, b) => a.at[1] - b.at[1]);
}

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
    reeds: {
        bare: nodeReedsBare,
        some: nodeReedsSome,
        plenty: nodeReedsPlenty,
    },
    deadfall: {
        bare: nodeDeadfallBare,
        some: nodeDeadfallSome,
        plenty: nodeDeadfallPlenty,
    },
    trunk: {
        bare: nodeTrunkBare,
        some: nodeTrunkSome,
        plenty: nodeTrunkPlenty,
    },
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
        // WHERE THE FOUR NODES STAND, AND WHY THEY OVERLAP ON PURPOSE.
        //
        // Every coordinate here was re-decided in F3.6 and the reasoning that
        // used to sit in this block is gone rather than amended, because all of
        // it argued about LABEL BOXES — fixed 8px text that no longer exists.
        // Reading the old numbers as if they still meant something is the trap
        // this file has warned about elsewhere and then fallen into twice.
        //
        // The measurement that decides the whole layout: the four cells are
        // 48x41, 44x36, 48x47 and 46x35, so they need 186 units of width in a
        // room that is 144 wide — before Blob's own silhouette (roughly x
        // -15..15 as drawn) and the shelter's 48-wide cell are counted. There
        // is no arrangement in which four objects this size stand apart. So
        // overlap is not a placement mistake here, it is forced, and the
        // question is only which overlaps read as depth and which read as a
        // collision.
        //
        // The answer, chosen from renders rather than arithmetic: nearer things
        // sit lower and overlap further things. A branch crossing in front of a
        // log is depth; a branch INSIDE a log is a bug. At the previous
        // coordinates the branches and the trunk overlapped by 40x36 units,
        // which meant the branches were effectively invisible, and the reeds
        // covered the hut by 42x31 while clipping the room's right wall.
        //
        // ORDER IN THIS ARRAY IS PAINT ORDER and now carries meaning:
        // `NodeLayer` draws them in sequence, so this list runs back to front —
        // trunk (y=42), heap (70), branches (74), reeds (76). The page's
        // hotspot loop walks the same order, so the nearer control also sits
        // above the further one where two hit regions overlap.
        nodes: [
            // THE FALLEN TRUNK, furthest back of the four and drawn first.
            // x=-44 puts its 48-wide cell at -68..-20, which clears the room's
            // left wall by 4 and clears Blob's drawn silhouette (-15) by 5.
            // y=42 stands it in the open floor rather than on the backdrop's
            // measured ground line at y=24 — that line is the clearing's BACK
            // WALL, and the shelter's own comment below records the four
            // heights rendered before that was believed. True for `some` and
            // `plenty` only: the trunk is deliberately NOT bottom-aligned
            // (see the trunk's own note in `scenes/README.md`), so at `bare`
            // — the band an unmet node sits in, and so the most-seen of the
            // eleven bands this file draws — the log's lowest opaque row
            // sits 10 units above the cell floor, and its visible base reads
            // nearer y≈32.
            { node: 'trunk', at: [-44, 42] },
            // THE HEAP, and the one node that cannot be placed cleanly. Its
            // 46-wide cell needs |x| >= 38 to clear Blob, and both such
            // positions collide with another node instead: at x=-40 it runs
            // into the trunk and the branches, at x=40 into the reeds by
            // 43x35. The room has no third place for an object this wide.
            //
            // So it overlaps Blob by 30x30 at [6, 70], deliberately, and that
            // is the least bad of the three: it sits at Blob's feet and in
            // FRONT, which reads as a pile Blob is standing behind. It is also
            // the only node that is transient — a heap exists solely for an
            // account that was handed a cabin, is never restocked, and is
            // deleted the moment it is drained — so of the four, it is the one
            // whose imperfect placement expires on its own.
            { node: 'salvage', at: [6, 70] },
            // THE FALLEN BRANCHES, crossing low in front of the trunk. The
            // 36x4 overlap with the trunk's cell is the depth cue, not a
            // collision: at the old [-46, 40] the two shared 40x36 units and
            // the branches were lost inside the log.
            { node: 'deadfall', at: [-34, 74] },
            // THE REEDS, nearest and drawn last. [44, 76] puts the cell at
            // x 20..68, y 35..76: inside the right wall with 4 to spare, and
            // starting one unit BELOW the shelter's cell, which ends at y=34.
            // That single unit is what stops the reeds covering the hut — at
            // the old [50, 44] they hid it by 42x31 and overhung the wall by 2.
            { node: 'reeds', at: [44, 76] },
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
        // THE CHEST. Occupancy was computed first, from every base centre
        // and cell already above: a 34x33 chest — CHEST_CELL — has no
        // collision-free position anywhere in this room, with or without a
        // heap standing. The one candidate small enough to clear everything,
        // 24x23, was the arithmetic's answer.
        //
        // A render overruled it. Four placements were rendered at both
        // sizes, over the real backdrop with the real Blob; at [30, 73] the
        // full 34x33 chest stands in open grass to Blob's right, with the
        // reed bed behind it and clear of Blob's own silhouette entirely.
        // The bounding boxes call that an overlap with the reeds (x 20..68);
        // the render calls it depth — the same rule that forced every node
        // above to overlap another. `scenes/README.md` "The chest" carries
        // the occupancy table and both rendered states (with a heap and
        // without).
        //
        // Base y 73 sits between the heap's 70 and the branches'/reeds'
        // 74/76, so `paintOrder` draws it after the heap and before the
        // reeds — the only two things it overlaps in x. The heap is
        // further and paints first; the reeds are nearer and paint last,
        // over it.
        chest: [30, 73],
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
