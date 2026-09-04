import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import { SCENES, sceneFor } from './scenes';

/**
 * Imported assets resolve to a root-relative URL under Vite, and this file
 * sits three directories below the project root.
 *
 * Joined by hand rather than through `new URL(…, import.meta.url)`: Vite
 * rewrites that form into an asset lookup of its own, and a path built from a
 * variable comes back `undefined` instead of pointing at the file.
 */
const PROJECT_ROOT = join(dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * A PNG's pixel dimensions, read out of its IHDR — the first chunk, at a
 * fixed offset, in every PNG there is.
 *
 * The header rather than a decoder: the invariant worth guarding is how long
 * a sheet is, and that is eight bytes 16 into the file. Nothing here needs to
 * know what the pixels are.
 */
function sheetSize(sheet: string): { width: number; height: number } {
    const bytes = readFileSync(join(PROJECT_ROOT, sheet));

    expect(bytes.subarray(1, 4).toString()).toBe('PNG');

    return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20) };
}

describe('scenes', () => {
    it('knows the two scenes E1 ships', () => {
        expect(Object.keys(SCENES).sort()).toEqual(['cabin', 'forest']);
    });

    /**
     * `scene.backdrops[part] ?? scene.base` (how `companion-room.tsx` reads
     * this) can't tell "this scene has no photographed backdrop, by design"
     * (the cabin) from "this scene lost one" — both look like a missing key
     * falling back to `base`. So the registry's own shape is checked
     * directly instead: a scene with any backdrop at all must have all four,
     * never a partial set that would show three lit skies and a dropped
     * frame at night. See `companion-room.test.tsx` for the guard that
     * actually exercises the compositor across the day rather than just the
     * registry's shape.
     */
    it('declares all four parts of day once it declares any backdrop', () => {
        // The source of truth is `config('companion.room')`, which the client
        // never reads at build time — so these four names are written out
        // again here, and a third time in `CompanionRoomConfigTest`
        // (`test_the_day_has_four_parts`). That one asserts config's keys by
        // exact equality, so a fifth part reddens there before it can show up
        // as an hour of flat green in the forest.
        const PARTS = ['sunrise', 'day', 'dusk', 'night'];

        for (const scene of Object.values(SCENES)) {
            const parts = Object.keys(scene.backdrops);

            if (parts.length > 0) {
                expect(parts.sort()).toEqual([...PARTS].sort());
            }
        }
    });

    /**
     * Naming a scene must never be able to break the screen — the same rule
     * item types, room objects and animations already follow. Includes a
     * plain-object prototype name: `SCENES['constructor']` resolves up the
     * prototype chain to a truthy value that `??` would never treat as
     * missing, which is exactly the failure `sceneFor` guards against by
     * checking the key is the record's own.
     */
    it('falls back rather than throwing on a scene it does not know', () => {
        expect(sceneFor('swamp')).toBe(SCENES.forest);
        expect(sceneFor('')).toBe(SCENES.forest);
        expect(sceneFor('constructor')).toBe(SCENES.forest);
    });

    /**
     * A backdrop that fails to load leaves this behind it, so Blob never
     * stands on nothing.
     */
    it('gives every scene a flat base colour', () => {
        for (const scene of Object.values(SCENES)) {
            expect(scene.base).toMatch(/^#[0-9A-Fa-f]{6}$/);
        }
    });
});

describe('foliage', () => {
    /**
     * The count guards in `companion-room.test.tsx` measure the drawing
     * against this list, so a list that quietly emptied would leave them
     * asserting that nothing equals nothing. This is where that is caught.
     */
    it('gives the forest foliage to move, and the cabin none', () => {
        expect(SCENES.forest.foliage.length).toBeGreaterThan(0);
        expect(SCENES.cabin.foliage).toHaveLength(0);
    });

    /**
     * `phase` is a frame index into the layer's own animation. One larger than
     * the animation has frames still draws — the modulo wraps it — so nothing
     * about the picture would say the offset no longer means what its author
     * wrote down.
     */
    it('names an animation the registry knows, and a phase inside it', () => {
        let checked = 0;

        for (const scene of Object.values(SCENES)) {
            for (const layer of scene.foliage) {
                const spec = ANIMATIONS[layer.animation];

                expect(spec).toBeDefined();
                expect(layer.phase ?? 0).toBeGreaterThanOrEqual(0);
                expect(layer.phase ?? 0).toBeLessThan(spec.frames);
                checked += 1;
            }
        }

        expect(checked).toBeGreaterThan(0);
    });

    /**
     * The sheet on disk is as long as the animation naming it says, and a
     * whole number of cells wide.
     *
     * `scenes/README.md` claimed a build-time assertion on the tree sheet
     * that only ever existed in the generation script, so this is the guard
     * that actually ships. It catches what a re-rolled sheet would do: a
     * different frame count leaves the window travelling over cells that are
     * not there, or stopping short of ones that are, and a width that is not
     * a multiple of the cell shears every frame after the first.
     *
     * The pixel-count invariant the README also records (all twelve tree
     * frames carrying the same 1259 opaque pixels) is not asserted here — it
     * needs the image decoded, and the README now says so rather than
     * claiming a guard.
     */
    it('carries as many frames on the sheet as the animation names', () => {
        let checked = 0;

        for (const scene of Object.values(SCENES)) {
            for (const layer of scene.foliage) {
                const [cellWidth, cellHeight] = layer.cell;
                const { frames } = ANIMATIONS[layer.animation];
                const { width, height } = sheetSize(layer.sheet);

                expect(width % cellWidth).toBe(0);
                expect(width).toBe(frames * cellWidth);
                expect(height).toBe(cellHeight);
                checked += 1;
            }
        }

        expect(checked).toBeGreaterThan(0);
    });

    /**
     * A layer placed off the edge is drawn, costs a frame's work and is never
     * seen — the one failure of these derived numbers that looks like nothing
     * at all rather than like a bug.
     */
    it('places every layer inside the room it is drawn in', () => {
        // The room's viewBox, written out because `ROOM` is private to
        // companion-room.tsx — the same reason the light's own test spells it
        // out rather than importing it.
        const room = { x: -72, y: -38, w: 144, h: 114 };
        let checked = 0;

        for (const scene of Object.values(SCENES)) {
            for (const layer of scene.foliage) {
                const [width, height] = layer.cell;
                const [x, y] = layer.at;

                expect(x).toBeGreaterThanOrEqual(room.x);
                expect(y).toBeGreaterThanOrEqual(room.y);
                expect(x + width).toBeLessThanOrEqual(room.x + room.w);
                expect(y + height).toBeLessThanOrEqual(room.y + room.h);
                checked += 1;
            }
        }

        expect(checked).toBeGreaterThan(0);
    });
});
