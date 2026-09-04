import { describe, expect, it } from 'vitest';

import { SCENES, sceneFor } from './scenes';

describe('scenes', () => {
    it('knows the two scenes E1 ships', () => {
        expect(Object.keys(SCENES).sort()).toEqual(['cabin', 'forest']);
    });

    it('gives every scene a backdrop for every part of the day', () => {
        for (const scene of Object.values(SCENES)) {
            for (const part of ['sunrise', 'day', 'dusk', 'night']) {
                expect(scene.backdrops[part] ?? scene.base).toBeTruthy();
            }
        }
    });

    /**
     * Naming a scene must never be able to break the screen — the same rule
     * item types, room objects and animations already follow.
     */
    it('falls back rather than throwing on a scene it does not know', () => {
        expect(sceneFor('swamp')).toBe(SCENES.forest);
        expect(sceneFor('')).toBe(SCENES.forest);
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
