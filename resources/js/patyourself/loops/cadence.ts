import type { ActiveActionData } from '@/patyourself/types';

/** The scheduling fields any cadence description is built from. */
type CadenceSource = Pick<
    ActiveActionData,
    'schedule_kind' | 'anchor' | 'recurrence' | 'next_occurrence_at'
> & {
    /**
     * The series anchor as an instant, ISO 8601. Read only to tell a series
     * that has not begun from one whose grid is exhausted for today.
     *
     * Optional, with `date` and `time`, because `currentCadenceLabel`'s caller
     * reads `ActiveActionData`, which carries none of the three — absent there,
     * this behaves exactly as it did before.
     */
    starts_at?: string | null;
    /** The anchor's date in the owner's zone, `YYYY-MM-DD`, formatted by the
     *  server. Displayed rather than derived from `starts_at`: re-deriving an
     *  instant in the browser's zone moves the day for anyone west of the
     *  owner, so a 23 Sep anchor would be named "22 Sep" beside a date input
     *  still reading 2026-09-23. */
    date?: string | null;
    /** The anchor's time of day in the owner's zone, `HH:MM`, formatted by the
     *  server for the same reason `date` is. */
    time?: string | null;
};

/**
 * A human-readable description of an action's cadence — "daily at 19:00",
 * "after brushing teeth".
 *
 * Combines recurrence and time only when both are present, returns whichever
 * single value exists otherwise, and returns null rather than a partial
 * string — never "daily at " with nothing after it. `next_occurrence_at` is
 * null whenever the occurrence grid has nothing left to report (a
 * cue-anchored action has no grid, and a clock action's grid only extends to
 * the end of the local day), and that is a legitimate state, not a gap to
 * paper over — but a recurring action still knows what time it runs at, so the
 * anchor's own time stands in rather than leaving the cadence unqualified.
 */
export function cadenceLabel(action: CadenceSource): string | null {
    if (action.schedule_kind === 'anchored') {
        return action.anchor === null ? null : `after ${action.anchor}`;
    }

    // A series that has not begun has materialised no occasions, so
    // `next_occurrence_at` is null and the lines below would read a bare
    // "weekly" — naming the cadence while saying nothing about the date that
    // was chosen for it. The anchor is what such an action is waiting on, so
    // it is what the line names.
    //
    // The *test* is on the instant, so the answer does not depend on the
    // browser's zone. A past anchor means the series is running, and then the
    // next occasion is the right thing to name — or nothing, for a grid already
    // exhausted for today. The *label* is the server's own `date` and `time`,
    // so what it names is the day the owner picked rather than whatever day
    // that instant falls on where the browser happens to be.
    //
    // All three are required: a caller carrying only the instant has nothing
    // safe to render, so it falls through to the next-occurrence line.
    const startsAt = action.starts_at ?? null;
    const date = action.date ?? null;
    const time = action.time ?? null;

    if (
        startsAt !== null &&
        date !== null &&
        time !== null &&
        new Date(startsAt) > new Date()
    ) {
        // A one-off is a single event, not a series, so it happens *on* its
        // date rather than running *from* it.
        const preposition = action.recurrence === null ? 'on' : 'from';
        const start = `${preposition} ${formatDay(date)} at ${time}`;

        return action.recurrence === null ? start : `${action.recurrence} ${start}`;
    }

    const nextTime =
        action.next_occurrence_at === null
            ? null
            : formatTime(action.next_occurrence_at);

    if (action.recurrence !== null) {
        // The grid only reaches the end of the local day, so a running series
        // has no slot to name on any day its cadence does not land on — six
        // days in seven for weekly, twenty-nine in thirty for monthly. The
        // anchor's time of day is still the time the cadence runs at, and
        // naming it is the difference between "monthly at 07:30" and a bare
        // "monthly", which says nothing about when.
        const at = nextTime ?? time;

        return at === null ? action.recurrence : `${action.recurrence} at ${at}`;
    }

    // A one-off whose occasion has gone has nothing left to report, and a time
    // on its own would name a cadence that no longer exists.
    return nextTime;
}

/**
 * The active action's cadence, named for the start-experiment form's keep
 * option so inheriting it is a legible choice rather than a guess at what
 * "keep" means. Null when the loop has no active action.
 */
export function currentCadenceLabel(
    activeAction: ActiveActionData | null,
): string | null {
    return activeAction === null ? null : cadenceLabel(activeAction);
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * "2026-09-23" → "23 Sep".
 *
 * Built from the parts rather than parsed: `new Date('2026-09-23')` is read as
 * UTC midnight and renders as the 22nd anywhere west of UTC, which is the very
 * shift formatting the date on the server exists to avoid. `new Date(y, m, d)`
 * is local midnight, so it renders as the same calendar day in every zone.
 */
function formatDay(localDate: string): string {
    const [year, month, day] = localDate.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}
