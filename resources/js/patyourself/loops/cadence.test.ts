import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { cadenceLabel } from './cadence';

const base = {
    schedule_kind: 'clock' as const,
    anchor: null,
    recurrence: 'weekly' as string | null,
    next_occurrence_at: null as string | null,
};

/**
 * The expected rendering of an instant, derived the way the suite already
 * derives it in show.test.tsx rather than hard-coded: vitest pins no TZ, so a
 * literal "23 Sep at 07:30" would pass on a UTC machine and fail everywhere
 * else. What is under test is which branch runs and how the parts are joined —
 * not whether Intl works.
 */
function renderedAs(iso: string): string {
    const date = new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
    const time = new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${date} at ${time}`;
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
        const startsAt = '2026-09-23T07:30:00Z';

        expect(cadenceLabel({ ...base, starts_at: startsAt })).toBe(
            `weekly from ${renderedAs(startsAt)}`,
        );
    });

    it('names the date alone for a one-off, whose date is the event', () => {
        const startsAt = '2026-09-23T07:30:00Z';

        expect(
            cadenceLabel({ ...base, recurrence: null, starts_at: startsAt }),
        ).toBe(`from ${renderedAs(startsAt)}`);
    });

    /**
     * An anchor in the past means the series is running, so its next occasion
     * is the useful fact — not the day it started. With no occasion left in
     * today's grid there is genuinely nothing to name, and a bare "weekly" is
     * the correct answer rather than a gap to paper over.
     */
    it('says nothing about a start date the series has already passed', () => {
        expect(
            cadenceLabel({ ...base, starts_at: '2026-09-09T07:30:00Z' }),
        ).toBe('weekly');
    });

    it('prefers the next occurrence once the series is running', () => {
        const next = '2026-09-16T07:30:00Z';
        const time = new Date(next).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        expect(
            cadenceLabel({
                ...base,
                starts_at: '2026-09-09T07:30:00Z',
                next_occurrence_at: next,
            }),
        ).toBe(`weekly at ${time}`);
    });

    it('is unchanged when no start date is carried at all', () => {
        const next = '2026-09-16T07:30:00Z';
        const time = new Date(next).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
        });

        expect(cadenceLabel({ ...base, next_occurrence_at: next })).toBe(
            `weekly at ${time}`,
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

    // The defect this function was fixed for once already: a recurrence with
    // no time left to report must not render as a dangling "weekly at ".
    it('renders no dangling cadence when there is nothing to name', () => {
        expect(cadenceLabel({ ...base, starts_at: null })).toBe('weekly');
        expect(
            cadenceLabel({ ...base, recurrence: null, starts_at: null }),
        ).toBeNull();
    });
});
