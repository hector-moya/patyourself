/**
 * The parts of Blob's day, and what each one carries.
 *
 * `config('companion.room')` authors them and the payload relays them
 * verbatim, so this is the only file that knows how to read one. It holds the
 * palette type as well as the predicates, which is what keeps the dependency
 * pointing one way: `companion.tsx` and `companion-room.tsx` both read the
 * clock, and neither of them can be read by it. Declaring the type in
 * `companion.tsx` and the predicates here would close a cycle instead.
 *
 * Every predicate takes the hour as an argument and reads nothing else. That
 * is not a testing convenience: what Blob is doing must be a function of the
 * clock and of config, never of the record, and the cheapest way to keep that
 * true is to leave these functions nothing else to read.
 */
export interface RoomPalette {
    /** The local hour this part of the day starts at. */
    from: number;
    wall: string;
    window: string;
    /** The colour the whole scene is washed with, Blob included. */
    light: string;
    /** How strongly. Zero at midday, when the light needs no help. */
    dim: number;
    /**
     * Whether Blob sleeps through this part.
     *
     * Authored in config beside the hours rather than inferred here from the
     * part's name or from how dark its wall reads. Which part of the day a
     * creature sleeps through is a decision, and a decision belongs written
     * down; the name is data everywhere else in this feature, and `partOfDay`
     * below does not know the names either.
     *
     * Absent is awake, so a room authored before this field existed behaves
     * exactly as it did.
     */
    asleep?: boolean;
}

/**
 * Which part of the day it is, from the CLIENT clock.
 *
 * Server time would be wrong for anyone not sitting on top of the server, and
 * the whole point is that the room looks different at breakfast and at dinner
 * for the person actually looking at it.
 *
 * Entries are read in `from` order and the last one that has started wins.
 * Before the earliest start the day has not begun yet, so it is still whatever
 * the last entry is — that is how night wraps past midnight without needing a
 * fourth state to describe the small hours.
 */
export function partOfDay(
    hour: number,
    room: Record<string, RoomPalette>,
): string {
    const parts = ordered(room);

    if (parts.length === 0) {
        return 'day';
    }

    const started = parts.filter(([, palette]) => palette.from <= hour);

    return started.length === 0
        ? parts[parts.length - 1][0]
        : started[started.length - 1][0];
}

/** Whether Blob is asleep at this hour. */
export function asleepAt(
    hour: number,
    room: Record<string, RoomPalette>,
): boolean {
    return room[partOfDay(hour, room)]?.asleep === true;
}

/**
 * Whether this is the part Blob wakes up in — the one that follows a sleeping
 * part, found by order rather than by name.
 *
 * There is no waking *moment*: nothing ticks the hour, and the clock stops
 * while the tab is hidden, so a boundary crossing is not an event this app can
 * see without a timer it has no reason to own. The morning is a part of the
 * day like any other, and what makes it the morning is what came before it.
 */
export function wakingAt(
    hour: number,
    room: Record<string, RoomPalette>,
): boolean {
    const parts = ordered(room);
    const current = partOfDay(hour, room);
    const index = parts.findIndex(([name]) => name === current);

    if (index === -1 || parts[index][1].asleep === true) {
        return false;
    }

    // Wrapping backwards off the front of the list: the morning's predecessor
    // is the night, which sorts last precisely because it starts latest.
    const previous = parts[(index - 1 + parts.length) % parts.length];

    return previous[1].asleep === true;
}

/** The parts, earliest first. Shared so nothing sorts them twice differently. */
function ordered(
    room: Record<string, RoomPalette>,
): [string, RoomPalette][] {
    return Object.entries(room).sort((a, b) => a[1].from - b[1].from);
}
