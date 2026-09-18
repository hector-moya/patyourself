/**
 * Companion payloads for tests. Shared because four screens render Blob and a
 * fifth copy of this object drifting out of step with the resolver is how a
 * green suite starts lying.
 *
 * Not a `.test.` file on purpose: importing one test module from another makes
 * vitest collect its cases twice.
 */
import type {
    CompanionBagData,
    CompanionData,
    CompanionUnlockData,
} from '@/patyourself/companion';

/**
 * An untouched bag: nothing met, nothing held, nothing chosen.
 *
 * Every list is present and empty rather than absent, exactly as the server
 * sends it — a screen should never have to ask whether a key exists.
 */
export function bag(overrides: Partial<CompanionBagData> = {}): CompanionBagData {
    return {
        xp: 0,
        capacity: 5,
        held: 0,
        name: 'Blob',
        items: [],
        // All three nodes stand in the clearing from the start, unmet and unusable —
        // the one list the server does NOT filter by what has happened, because
        // a node being there is not a preview of anything.
        nodes: [
            {
                node: 'deadfall',
                label: 'the fallen branches',
                available: 0,
                skill: 'gather-wood',
                met: false,
                known: false,
                usable: false,
            },
            {
                node: 'reeds',
                label: 'the reeds',
                available: 0,
                skill: 'gather-fibre',
                met: false,
                known: false,
                usable: false,
            },
            {
                node: 'trunk',
                label: 'the fallen trunk',
                available: 0,
                skill: 'chop-wood',
                met: false,
                known: false,
                usable: false,
            },
        ],
        skills: [],
        recipes: [],
        ...overrides,
    };
}

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
        // Unnamed, which is what the server sends for a companion nobody has
        // renamed — the fallback happens on the way out of the resolver, so
        // the client never receives an empty name in practice.
        name: 'Blob',
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
                asleep: true,
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
