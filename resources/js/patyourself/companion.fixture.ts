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
        //
        // `bare` is the band an unmet node sits in and stays in: nothing stocks
        // a node whose skill has not been bought, so this is what a new record
        // actually sees, for as long as it takes to buy one. A test that wants
        // a node with something at it must set BOTH `available` and `band` —
        // they are the payload's two views of one fact and the server keeps
        // them in step, so a fixture that moves one alone is describing a
        // payload the server cannot send.
        nodes: [
            {
                node: 'deadfall',
                label: 'the fallen branches',
                available: 0,
                band: 'bare',
                skill: 'gather-wood',
                met: false,
                known: false,
                usable: false,
            },
            {
                node: 'reeds',
                label: 'the reeds',
                available: 0,
                band: 'bare',
                skill: 'gather-fibre',
                met: false,
                known: false,
                usable: false,
            },
            {
                node: 'trunk',
                label: 'the fallen trunk',
                available: 0,
                band: 'bare',
                skill: 'chop-wood',
                met: false,
                known: false,
                usable: false,
            },
            // The heap: absent from most clearings, and absent from this
            // fixture until F3.6 drew it. A node without a skill is not the
            // world — it is there only because something put it there, nothing
            // restocks it, and `HarvestNode` deletes it once drained — so
            // `skill` is null, `known` is true with nothing to learn, and there
            // is no `bare` band it can ever be in.
            //
            // Present with `available: 0` and no band on purpose: that is the
            // shape a test gets by default, and a test about the heap has to
            // say what is in it. The DEFAULT here must not draw a heap, or
            // every clearing case in the suite silently gains a fifth object.
            //
            // This IS the impossible payload the warning above describes —
            // the server never sends a heap at `available: 0` with an empty
            // band; `CompanionBag::nodes()` skips a skill-less node with
            // nothing standing rather than sending one at zero. Kept anyway
            // because it is what buys the other two things this row exists
            // for: every test can FIND a heap row to override, and an empty
            // band has no sprite, so the default clearing stays at four
            // authored nodes instead of quietly drawing a fifth.
            {
                node: 'salvage',
                label: 'the heap',
                available: 0,
                band: '',
                skill: null,
                met: true,
                known: true,
                usable: true,
            },
        ],
        skills: [],
        recipes: [],
        // Nothing built and nothing offered, which is what an untouched
        // clearing sends: the offer waits on a price Blob can account for.
        shelter: { built: null, label: null, offer: null },
        // Nothing built and nothing at home, which is what an untouched
        // clearing sends. The DEFAULT here must not draw a chest, or every
        // clearing case in the suite silently gains a sixth object — the same
        // reasoning the heap's row above records.
        stash: { standing: false, items: [] },
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
