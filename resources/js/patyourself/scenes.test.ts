import { describe, expect, it } from 'vitest';

import { ANIMATIONS } from './companion-animations';
import { SCENES, sceneFor } from './scenes';

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
