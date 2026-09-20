import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import { SCENES, sceneFor, SHELTER_CELL, SHELTER_SPRITES, shelterSprite } from './scenes';

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

describe('nodes and the shelter', () => {
    /**
     * The heap has a place to stand even though most clearings never have one.
     * `scenes.ts` knows WHERE things are; whether a given clearing has one is
     * the server's answer, and the page skips any spec the payload does not
     * carry.
     */
    it('knows where the heap stands, whether or not one is there', () => {
        expect(SCENES.forest.nodes.map((node) => node.node).sort()).toEqual([
            'deadfall',
            'reeds',
            'salvage',
            'trunk',
        ]);
    });

    /** And where the shelter stands, which is outdoors and nowhere else. */
    it('gives the forest somewhere to put a shelter, and the cabin none', () => {
        expect(SCENES.forest.shelter).toBeDefined();
        expect(SCENES.cabin.shelter).toBeUndefined();
    });

    /**
     * No two things in the clearing share a point. This cannot prove they do
     * not OVERLAP — jsdom has no layout engine and the labels are sized in
     * fixed pixels — but it does catch the one mistake arithmetic can catch,
     * which is two objects authored at the same place.
     */
    it('stands everything in the clearing somewhere different', () => {
        const points = [
            ...SCENES.forest.nodes.map((node) => `${node.at[0]},${node.at[1]}`),
            `${SCENES.forest.shelter?.[0]},${SCENES.forest.shelter?.[1]}`,
        ];

        expect(new Set(points).size).toBe(points.length);
    });

    /**
     * Distinctness alone lets a node move back onto the shelter's own
     * footprint, as long as it lands on a point that is not the shelter's
     * exact `at` — the reeds' pre-phase position, `[44, 36]`, is such a
     * point, and it is why the reeds sit at `[50, 44]` now. This expresses
     * the rule the move exists to keep, derived from `SCENES.forest.shelter`
     * and `SHELTER_CELL` rather than pasted, so it survives a deliberate
     * coordinate change and only fails on a wrong derivation — and, unlike
     * overlap, is a structural property jsdom can actually check.
     */
    it('keeps every node clear of the shelter\'s own cell', () => {
        const [shelterX, shelterY] = SCENES.forest.shelter!;
        // The cell `ShelterLayer` actually draws: top-left
        // `(shelterX − SHELTER_CELL/2, shelterY − SHELTER_CELL)`, `SHELTER_CELL`
        // square — see `scenes.ts`'s own docblock on `SceneSpec.shelter`.
        const cell = {
            minX: shelterX - SHELTER_CELL / 2,
            maxX: shelterX + SHELTER_CELL / 2,
            minY: shelterY - SHELTER_CELL,
            maxY: shelterY,
        };

        for (const node of SCENES.forest.nodes) {
            const [x, y] = node.at;
            const insideTheCell =
                x >= cell.minX &&
                x <= cell.maxX &&
                y >= cell.minY &&
                y <= cell.maxY;

            expect({ node: node.node, insideTheCell }).toEqual({
                node: node.node,
                insideTheCell: false,
            });
        }
    });
});

describe('the shelter sprites', () => {
    it('draws every stage on the same 48x48 cell', () => {
        for (const [stage, sheet] of Object.entries(SHELTER_SPRITES)) {
            const { width, height } = sheetSize(sheet);

            expect(`${stage}: ${width}x${height}`).toBe(`${stage}: ${SHELTER_CELL}x${SHELTER_CELL}`);
        }
    });

    it('has a sprite for every stage the shelter config can reach', () => {
        expect(Object.keys(SHELTER_SPRITES).sort()).toEqual(['cabin', 'hut', 'lean-to']);
    });

    /**
     * Which sheet, not just that one exists: the key set alone, the PNG
     * header (identical for all three by construction) and `data-shelter`
     * (which echoes the stage name, not the file) all stay green if
     * `'lean-to'` and `'cabin'` swap sheets — the clearing would then draw a
     * cabin when the lean-to goes up and a lean-to when the cabin is
     * finished, the upgrade arc running backwards. The Vite-resolved URL is
     * the one thing that carries the filename.
     */
    it('draws the stage it names, not a different one wearing its key', () => {
        expect(shelterSprite('lean-to')).toContain('shelter-lean-to');
        expect(shelterSprite('hut')).toContain('shelter-hut');
        expect(shelterSprite('cabin')).toContain('shelter-cabin');
    });

    it('does not resolve a stage it has no sprite for', () => {
        // `constructor` resolves to a truthy function through the prototype
        // chain on a bare lookup. BLOB.md §12 records that ROOM_OBJECTS and
        // SPRITE_ITEMS still carry that bug and only scenes.ts was fixed.
        expect(shelterSprite('constructor')).toBeUndefined();
        expect(shelterSprite('mansion')).toBeUndefined();
    });

    it('stands the shelter in the clearing, above the measured ground line', () => {
        // The measured ground line (PNG row 62, where PNG row = y + 38) is
        // y=24 — but a render showed a building standing there reads as
        // being IN the trees, because that line is the clearing's back wall
        // rather than its open floor. Four heights were rendered; y=34 is
        // the one the owner picked as actually standing in the clearing.
        expect(SCENES.forest.shelter).toEqual([44, 34]);
    });
});
