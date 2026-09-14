import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { cadenceLabel } from './cadence';

const base = {
    schedule_kind: 'clock' as const,
    anchor: null,
    recurrence: 'weekly' as string | null,
    next_occurrence_at: null as string | null,
};

/**
 * The expected rendering of the server's own date, derived the way the function
 * derives it rather than hard-coded: vitest pins no TZ and no ICU version, so a
 * literal "23 Sep" would pass on one machine and fail on another. What is under
 * test is which branch runs and how the parts are joined.
 *
 * Built from the parts, exactly as `formatDay` is, because the whole point of
 * the field is that it never goes through `new Date('2026-09-23')` — that is
 * UTC midnight, and it renders as the 22nd west of UTC.
 */
function renderedDay(localDate: string): string {
    const [year, month, day] = localDate.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function renderedTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

describe('cadenceLabel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-14T12:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    /**
     * A series that has not begun has materialised no occasions, so
     * next_occurrence_at is null and the line would otherwise read a bare
     * "weekly" — naming the cadence while saying nothing about the date that
     * was just chosen for it.
     */
    it('names the date a series has not reached yet', () => {
        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-23T07:30:00Z',
                date: '2026-09-23',
                time: '07:30',
            }),
        ).toBe(`weekly from ${renderedDay('2026-09-23')} at 07:30`);
    });

    /** A one-off is a single event, so it happens *on* a day rather than *from* it. */
    it('names the date alone for a one-off, whose date is the event', () => {
        expect(
            cadenceLabel({
                ...base,
                recurrence: null,
                starts_at: '2026-09-23T07:30:00Z',
                date: '2026-09-23',
                time: '07:30',
            }),
        ).toBe(`on ${renderedDay('2026-09-23')} at 07:30`);
    });

    /**
     * The defect this guards: deriving the label from the instant renders the
     * owner's 23 Sep 07:30 as "22 Sep at 02:30" on a laptop set to New York —
     * a different day from the one in their own date input.
     *
     * Asserted by handing the function a `date` and `time` that do not describe
     * `starts_at` at all. Only the server's fields can produce this string, and
     * it is the same string in every zone: a local-midnight date renders as its
     * own calendar day everywhere, and the time is passed through verbatim.
     */
    it('names the server’s own date and time rather than re-deriving the instant', () => {
        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-23T07:30:00Z',
                date: '2026-12-25',
                time: '18:45',
            }),
        ).toBe(`weekly from ${renderedDay('2026-12-25')} at 18:45`);
    });

    /**
     * `currentCadenceLabel` hands this function an `ActiveActionData`, which
     * carries none of the three fields. A caller carrying only the instant has
     * no server-formatted day to name, so it must fall through rather than
     * derive one.
     */
    it('says nothing about a start date it was given no formatted day for', () => {
        const next = '2026-09-16T07:30:00Z';

        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-23T07:30:00Z',
                next_occurrence_at: next,
            }),
        ).toBe(`weekly at ${renderedTime(next)}`);
    });

    /**
     * An anchor in the past means the series is running, so its next occasion
     * is the useful fact — not the day it started. With no occasion left in
     * today's grid the start date is not what stands in: the anchor's time of
     * day is, because that is still the time the cadence runs at.
     */
    it('says nothing about a start date the series has already passed', () => {
        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-09T07:30:00Z',
                date: '2026-09-09',
                time: '07:30',
            }),
        ).toBe('weekly at 07:30');
    });

    /**
     * The grid reaches the end of the local day and no further, so a running
     * monthly action has no slot to name on twenty-nine days in thirty, and a
     * weekly one on six in seven. A bare "monthly" names the cadence while
     * saying nothing about when it happens.
     *
     * The time is the server's own `time`, passed through verbatim rather than
     * derived from an instant, so the string is the same in every zone — the
     * same reason the start-date line reads it.
     */
    it.each(['weekly', 'fortnightly', 'monthly'])(
        'names the time a running %s action runs at when its grid has no slot left',
        (recurrence) => {
            expect(
                cadenceLabel({
                    ...base,
                    recurrence,
                    starts_at: '2026-08-31T07:30:00Z',
                    date: '2026-08-31',
                    time: '07:30',
                }),
            ).toBe(`${recurrence} at 07:30`);
        },
    );

    /**
     * The next occurrence still wins where there is one: it is the nearer fact,
     * and it is the one that moves.
     */
    it('prefers the next occurrence to the anchor time', () => {
        const next = '2026-09-14T18:45:00Z';

        expect(
            cadenceLabel({
                ...base,
                recurrence: 'monthly',
                starts_at: '2026-08-31T07:30:00Z',
                date: '2026-08-31',
                time: '07:30',
                next_occurrence_at: next,
            }),
        ).toBe(`monthly at ${renderedTime(next)}`);
    });

    /**
     * A one-off keeps its old answer. Its occasion has gone, so there is no
     * cadence left to qualify, and a bare time would name one it never had.
     */
    it('says nothing for a one-off whose occasion has gone', () => {
        expect(
            cadenceLabel({
                ...base,
                recurrence: null,
                starts_at: '2026-09-09T07:30:00Z',
                date: '2026-09-09',
                time: '07:30',
            }),
        ).toBeNull();
    });

    it('prefers the next occurrence once the series is running', () => {
        const next = '2026-09-16T07:30:00Z';

        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-09T07:30:00Z',
                date: '2026-09-09',
                time: '07:30',
                next_occurrence_at: next,
            }),
        ).toBe(`weekly at ${renderedTime(next)}`);
    });

    it('is unchanged when no start date is carried at all', () => {
        const next = '2026-09-16T07:30:00Z';

        expect(cadenceLabel({ ...base, next_occurrence_at: next })).toBe(
            `weekly at ${renderedTime(next)}`,
        );
    });

    it('describes a cue-anchored action by its phrase', () => {
        expect(
            cadenceLabel({
                schedule_kind: 'anchored',
                anchor: 'brushing my teeth',
                recurrence: null,
                next_occurrence_at: null,
                starts_at: null,
            }),
        ).toBe('after brushing my teeth');
    });

    /**
     * Retire an action, start a revision with no revised action, and
     * StartExperiment writes `metadata: []` — a cue-anchored kind with no
     * phrase behind it. There is nothing to say, so the line is omitted.
     */
    it('says nothing about a cue-anchored action with no phrase', () => {
        expect(
            cadenceLabel({
                schedule_kind: 'anchored',
                anchor: null,
                recurrence: 'weekly',
                next_occurrence_at: '2026-09-16T07:30:00Z',
                date: '2026-09-16',
                time: '07:30',
            }),
        ).toBeNull();
    });

    /**
     * The realistic state of an overdue one-off whose occasion is still
     * unlogged: no recurrence to name, but a slot still waiting. The time is
     * the whole answer — "once at 07:30" would invent a cadence the action
     * does not have.
     */
    it('names the time alone when there is no recurrence to pair it with', () => {
        const next = '2026-09-14T07:30:00Z';

        expect(
            cadenceLabel({
                ...base,
                recurrence: null,
                next_occurrence_at: next,
            }),
        ).toBe(renderedTime(next));
    });

    // The defect this function was fixed for once already: a recurrence with
    // no time left to report must not render as a dangling "weekly at ".
    // `base` carries neither a next occurrence nor an anchor time, so both
    // sources of a time are absent and the bare cadence is all there is.
    it('renders no dangling cadence when there is nothing to name', () => {
        expect(cadenceLabel({ ...base, starts_at: null })).toBe('weekly');
        expect(
            cadenceLabel({ ...base, recurrence: null, starts_at: null }),
        ).toBeNull();
        expect(cadenceLabel({ ...base, starts_at: null, time: null })).toBe(
            'weekly',
        );
    });

    it.each(['fortnightly', 'monthly'])(
        'reads a %s cadence out with no special case',
        (recurrence) => {
            const next = '2026-09-16T07:30:00Z';
            const time = new Date(next).toLocaleTimeString('en-GB', {
                hour: '2-digit',
                minute: '2-digit',
            });

            expect(
                cadenceLabel({ ...base, recurrence, next_occurrence_at: next }),
            ).toBe(`${recurrence} at ${time}`);
        },
    );
});
