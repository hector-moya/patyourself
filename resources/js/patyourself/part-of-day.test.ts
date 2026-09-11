import { describe, expect, it } from 'vitest';

import { companion } from './companion.fixture';
import { asleepAt, partOfDay, wakingAt } from './part-of-day';
import type { RoomPalette } from './part-of-day';

/** The room the fixture ships: three parts, and hour 6 is still night in it. */
const ROOM = companion().room;

/**
 * A four-part day in the same shape as `config('companion.room')`, built here
 * rather than added to the shared fixture: an existing case asserts hour 6 is
 * night in the fixture's three-part room, and a sunrise part would turn it red.
 */
function fourParts(
    overrides: Record<string, Partial<RoomPalette>> = {},
): Record<string, RoomPalette> {
    const base: Record<string, RoomPalette> = {
        sunrise: { from: 5, wall: '#F2E0D0', window: '#F0B98A', light: '#F4A15C', dim: 0.18 },
        day: { from: 8, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
        dusk: { from: 18, wall: '#E7D2BE', window: '#E9A468', light: '#E2762F', dim: 0.22 },
        night: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42, asleep: true },
    };

    for (const [name, patch] of Object.entries(overrides)) {
        base[name] = { ...base[name], ...patch };
    }

    return base;
}

describe('partOfDay', () => {
    it('reads the hour against the parts config defines', () => {
        expect(partOfDay(9, ROOM)).toBe('day');
        expect(partOfDay(17, ROOM)).toBe('day');
        expect(partOfDay(19, ROOM)).toBe('dusk');
        expect(partOfDay(22, ROOM)).toBe('night');
    });

    /**
     * The small hours are still night. Wrapping past midnight falls out of
     * reading the parts in order rather than out of a fourth state describing
     * 3am.
     */
    it('wraps past midnight without a fourth state', () => {
        expect(partOfDay(0, ROOM)).toBe('night');
        expect(partOfDay(3, ROOM)).toBe('night');
        expect(partOfDay(6, ROOM)).toBe('night');
    });

    /**
     * A part of day the config does not name falls back to `day` — one of the
     * spec's error-handling requirements, and the only way to reach it is a
     * room that names no parts at all, since every other value `partOfDay`
     * can return is a key it just read out of that same object.
     */
    it('falls back to day when the config names no parts at all', () => {
        expect(partOfDay(9, {})).toBe('day');
        expect(partOfDay(23, {})).toBe('day');
    });
});

/** The fixture's own three parts, with nothing flagged. */
const ROOM_WITHOUT_FLAG: Record<string, RoomPalette> = {
    day: { from: 7, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
    dusk: { from: 18, wall: '#E7D2BE', window: '#E9A468', light: '#E2762F', dim: 0.22 },
    night: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42 },
};

describe('asleepAt', () => {
    it('is true through the part config flags, and false everywhere else', () => {
        expect(asleepAt(22, fourParts())).toBe(true);
        expect(asleepAt(3, fourParts())).toBe(true);
        expect(asleepAt(6, fourParts())).toBe(false);
        expect(asleepAt(12, fourParts())).toBe(false);
        expect(asleepAt(19, fourParts())).toBe(false);
    });

    /**
     * The flag is the whole mechanism, so a room without it has to behave
     * exactly as this feature's rooms did before it existed. This is also what
     * makes the flag falsifiable: the fixture's own night carries it, so
     * without this case a test asserting sleep would pass against a default.
     */
    it('is false for a room whose parts carry no flag at all', () => {
        expect(asleepAt(22, fourParts({ night: { asleep: undefined } }))).toBe(false);
        expect(asleepAt(3, ROOM_WITHOUT_FLAG)).toBe(false);
    });

    it('reads the flag as a value, not as truthiness', () => {
        // A part that says anything other than `true` is awake. The cast is
        // the point: the room is authored in PHP and relayed unvalidated, so
        // `'asleep' => 1` really can arrive here, and only a truthy
        // non-boolean tells `=== true` apart from a truthiness check.
        const truthy = fourParts({ night: { asleep: 1 as unknown as boolean } });

        expect(asleepAt(22, truthy)).toBe(false);
        expect(asleepAt(22, fourParts({ night: { asleep: false } }))).toBe(false);
    });

    it('is false when the config names no parts at all', () => {
        expect(asleepAt(22, {})).toBe(false);
    });

    it('sleeps through every part when config flags every part', () => {
        const room = fourParts({
            sunrise: { asleep: true },
            day: { asleep: true },
            dusk: { asleep: true },
        });

        expect(asleepAt(6, room)).toBe(true);
        expect(asleepAt(12, room)).toBe(true);
    });
});

describe('wakingAt', () => {
    /**
     * Derived, not named: this room's parts are called nonsense and the hours
     * are unchanged, so the only thing left to key off is the order. A
     * hardcoded 'sunrise' fails here.
     */
    it('is the part that follows the sleeping one, whatever it is called', () => {
        const renamed: Record<string, RoomPalette> = {
            morgunn: { from: 5, wall: '#F2E0D0', window: '#F0B98A', light: '#F4A15C', dim: 0.18 },
            dagur: { from: 8, wall: '#EFE6D6', window: '#B9D5E4', light: '#FFFFFF', dim: 0 },
            kvold: { from: 21, wall: '#2F3A40', window: '#1A2530', light: '#2B3F6B', dim: 0.42, asleep: true },
        };

        expect(wakingAt(6, renamed)).toBe(true);
        expect(wakingAt(12, renamed)).toBe(false);
        expect(wakingAt(22, renamed)).toBe(false);
    });

    it('is true only in the waking part of a four-part day', () => {
        expect(wakingAt(6, fourParts())).toBe(true);
        expect(wakingAt(9, fourParts())).toBe(false);
        expect(wakingAt(19, fourParts())).toBe(false);
        expect(wakingAt(23, fourParts())).toBe(false);
    });

    it('is never true while Blob is asleep', () => {
        const room = fourParts({
            sunrise: { asleep: true },
            day: { asleep: true },
            dusk: { asleep: true },
        });

        for (const hour of [0, 6, 12, 19, 22]) {
            expect(wakingAt(hour, room)).toBe(false);
        }
    });

    it('is false when no part sleeps, and when there are no parts', () => {
        expect(wakingAt(6, fourParts({ night: { asleep: undefined } }))).toBe(false);
        expect(wakingAt(6, {})).toBe(false);
    });
});
