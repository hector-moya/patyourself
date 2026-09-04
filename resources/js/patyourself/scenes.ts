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
