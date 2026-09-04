/**
 * Companion payloads for tests. Shared because four screens render Blob and a
 * fifth copy of this object drifting out of step with the resolver is how a
 * green suite starts lying.
 *
 * Not a `.test.` file on purpose: importing one test module from another makes
 * vitest collect its cases twice.
 */
import type {
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';

export function unlock(
    overrides: Partial<CompanionUnlockData> = {},
): CompanionUnlockData {
    return {
        kind: 'body',
        name: 'blob',
        variant: null,
        room_object: null,
        message: 'Blob is here.',
        unlocked_at: '2026-08-27T09:00:00+00:00',
        ...overrides,
    };
}

/** Blob, just arrived: one outcome logged and nothing else. */
export function companion(
    overrides: Partial<CompanionData> = {},
): CompanionData {
    return {
        log_count: 1,
        insight_count: 0,
        stage_index: 1,
        features: ['blob'],
        items: [],
        abilities: [],
        room_objects: [],
        unlocks: [unlock()],
        latest_unlock: unlock(),
        renderer: 'svg',
        // Indoors by default: most of this suite predates scenes and asserts
        // on the room's own wall, floor and objects without naming one.
        // Tests about the forest override this explicitly.
        scene: 'cabin',
        room: {
            day: {
                from: 7,
                wall: '#EFE6D6',
                window: '#B9D5E4',
                light: '#FFFFFF',
                dim: 0,
            },
            dusk: {
                from: 18,
                wall: '#E7D2BE',
                window: '#E9A468',
                light: '#E2762F',
                dim: 0.22,
            },
            night: {
                from: 21,
                wall: '#2F3A40',
                window: '#1A2530',
                light: '#2B3F6B',
                dim: 0.42,
            },
        },
        ...overrides,
    };
}

/** The record before anything is in it, which is where Blob starts. */
export function noCompanion(
    overrides: Partial<CompanionData> = {},
): CompanionData {
    return companion({
        log_count: 0,
        stage_index: 0,
        features: [],
        unlocks: [],
        latest_unlock: null,
        ...overrides,
    });
}
