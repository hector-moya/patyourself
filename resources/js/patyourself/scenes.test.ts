import { describe, expect, it } from 'vitest';

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
