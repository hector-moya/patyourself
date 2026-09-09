import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

/** CoachLayout renders `<Head>` and the desktop rail reads `usePage()` —
 *  neither has a live Inertia app behind it in a component test, the same
 *  seam `exercise.test.tsx` stubs. */
const page = {
    url: '/exercises/9/progression',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import ProgressionScreen, { formatSession } from './progression';
import type {
    ProgressionProps,
    ProgressionSession,
    ProgressionSet,
} from './progression';

function session(
    overrides: Partial<ProgressionSession> = {},
): ProgressionSession {
    return {
        occurrence_id: 1,
        performed_at: '2026-09-02T09:00:00+00:00',
        sets: [{ reps: 10, weight: 60 }],
        ...overrides,
    };
}

function renderProgression(overrides: Partial<ProgressionProps> = {}) {
    const props: ProgressionProps = {
        exercise: { id: 9, name: 'Bench press' },
        sessions: [],
        ...overrides,
    };

    return render(<ProgressionScreen {...props} />);
}

describe('formatSession', () => {
    /**
     * Killing mutation: always take the mixed-weight branch (e.g. drop the
     * `distinctWeights.size === 1` check). A uniform session would then read
     * "60kg × 10 / 60kg × 10 / 65kg × 8"-style, one "kg" per set instead of
     * one weight for the whole line — verified by direct mutation and rerun.
     */
    it('formats a uniform-weight session as "60kg · 10 / 10 / 8"', () => {
        const sets: ProgressionSet[] = [
            { reps: 10, weight: 60 },
            { reps: 10, weight: 60 },
            { reps: 8, weight: 60 },
        ];

        expect(formatSession(sets)).toBe('60kg · 10 / 10 / 8');
    });

    /**
     * A 60/60/65 session must spell out every set's own weight, never
     * collapse to the first set's 60kg.
     *
     * Killing mutation: always take the uniform branch (read `sets[0].weight`
     * regardless of whether the rest agree). The result would then read
     * "60kg · 10 / 10 / 8" — the same string the uniform test above expects,
     * for a session that was never uniform — verified by direct mutation and
     * rerun.
     */
    it('formats a mixed-weight session per set, never collapsing to the first weight', () => {
        const sets: ProgressionSet[] = [
            { reps: 10, weight: 60 },
            { reps: 10, weight: 60 },
            { reps: 8, weight: 65 },
        ];

        expect(formatSession(sets)).toBe('60kg × 10 / 60kg × 10 / 65kg × 8');
    });

    /**
     * Killing mutation: format a null weight as `` `${weight ?? 0}kg` `` in
     * the uniform branch instead of omitting it. The result would then
     * contain "0kg" for a body-weight session — verified by direct mutation
     * and rerun.
     */
    it('formats a body-weight session as reps only, never "0kg"', () => {
        const sets: ProgressionSet[] = [
            { reps: 12, weight: null },
            { reps: 10, weight: null },
        ];

        const result = formatSession(sets);

        expect(result).toBe('12 / 10');
        expect(result).not.toMatch(/\b0kg\b/);
    });

    /**
     * A session with one body-weight set and two loaded sets at different
     * weights takes the mixed branch (three distinct values: null, 60, 65)
     * and must render the null set as bare reps, not "0kg".
     *
     * Killing mutation: format a null weight as `` `${set.weight ?? 0}kg` ``
     * in the mixed branch. The result would then contain "0kg" — verified by
     * direct mutation and rerun.
     */
    it('formats a mixed session containing a body-weight set without printing "0kg"', () => {
        const sets: ProgressionSet[] = [
            { reps: 10, weight: null },
            { reps: 10, weight: 60 },
            { reps: 8, weight: 65 },
        ];

        const result = formatSession(sets);

        expect(result).toBe('10 / 60kg × 10 / 65kg × 8');
        expect(result).not.toMatch(/\b0kg\b/);
    });
});

describe('ProgressionScreen', () => {
    /**
     * `sessions` arrives newest first and the screen must render it as
     * given, not re-sort it. Also pins the wiring between `formatSession`
     * and the row itself — `formatSession` is well covered as a pure
     * function above, but that proves nothing about what actually reaches
     * the DOM.
     *
     * The older row is given an empty `sets` array rather than a second
     * populated one, which incidentally exercises `formatSession([])`'s
     * guard against the rendered output rather than only against the
     * function directly.
     *
     * Killing mutation: `.slice().reverse()` the sessions before mapping.
     * The two rows would then swap DOM order — verified by direct mutation
     * and rerun.
     *
     * Killing mutation (rendered text): blank the `formatSession` call at
     * `progression.tsx:69` (`{formatSession(session.sets)}` → `{''}`), or
     * delete the date `<span>` at `:71-76`. Either would leave the row's
     * own weight/reps or date text absent while every other test — none of
     * which reads a row's text — stays green. Verified by direct mutation
     * and rerun; raw output captured in the task report.
     */
    it('renders one row per session, newest first', () => {
        renderProgression({
            sessions: [
                session({
                    occurrence_id: 2,
                    performed_at: '2026-09-02T09:00:00+00:00',
                    sets: [
                        { reps: 10, weight: 60 },
                        { reps: 10, weight: 60 },
                        { reps: 8, weight: 60 },
                    ],
                }),
                session({
                    occurrence_id: 1,
                    performed_at: '2026-08-29T09:00:00+00:00',
                    sets: [],
                }),
            ],
        });

        const rows = screen.getAllByTestId(/^progression-row-/);

        expect(rows.map((row) => row.dataset.testid)).toEqual([
            'progression-row-2',
            'progression-row-1',
        ]);
        expect(screen.getByTestId('progression-row-2')).toHaveTextContent(
            '60kg · 10 / 10 / 8',
        );
        expect(screen.getByTestId('progression-row-2')).toHaveTextContent(
            '2 Sep',
        );
    });

    /**
     * Killing mutation: render the session list unconditionally instead of
     * guarding on `sessions.length === 0`. The empty copy would then be
     * absent — verified by direct mutation and rerun.
     */
    it('renders an empty state when there is no history, and no chart, table or axis', () => {
        const { container } = renderProgression({ sessions: [] });

        // Scoped to `<main>`: `CoachLayout` always renders the desktop rail's
        // own logo/nav svgs alongside it, which are chrome, not this screen.
        const main = container.querySelector('main');

        expect(screen.getByTestId('progression-empty')).toBeInTheDocument();
        expect(
            screen.queryByTestId('progression-list'),
        ).not.toBeInTheDocument();
        expect(main).not.toBeNull();
        expect(main?.querySelector('table')).toBeNull();
        expect(main?.querySelector('svg')).toBeNull();
        expect(main?.querySelector('canvas')).toBeNull();
        expect(main?.textContent ?? '').not.toMatch(/axis/i);
    });

    /**
     * This screen has two entry points — the exercise screen mid-session,
     * and a routine row on the loop's own record — with nothing in the
     * payload saying which was used, so it renders no back control at all.
     *
     * Killing mutation: pass a `headerLeading` link into `CoachLayout`. An
     * anchor would then appear inside the header — verified by direct
     * mutation and rerun.
     */
    it('renders no back control — the screen has two entry points and cannot know which was used', () => {
        const { container } = renderProgression({
            sessions: [session()],
        });

        const header = container.querySelector('header');

        expect(header).not.toBeNull();
        expect(header?.querySelectorAll('a').length).toBe(0);
    });

    /**
     * Record, never prescribe: nothing on this screen may name a record,
     * cite a 1RM, show a percentage, or call a trend progress.
     *
     * Killing mutation: add the word "progress" to the heading (or any
     * other prescriptive word in this list) anywhere in the render — turns
     * one of these assertions red — verified by direct mutation and rerun.
     */
    it('says nothing prescriptive: no "record", "best", "1RM", "progress", "percentage", "target"', () => {
        const { container } = renderProgression({
            sessions: [
                session({
                    sets: [
                        { reps: 10, weight: 60 },
                        { reps: 10, weight: 60 },
                        { reps: 8, weight: 65 },
                    ],
                }),
            ],
        });

        // Scoped to `<main>` — the desktop rail's own "Progress" nav label
        // is chrome, not this screen, and would otherwise false-positive.
        // Tags are replaced with spaces rather than reading `textContent`
        // directly: adjacent elements concatenate with no whitespace (e.g.
        // this paragraph immediately followed by a row starting "60kg…"
        // reads as "…progress60kg…"), which can hide a banned word from a
        // `\b`-bounded regex at the point two elements meet.
        const main = container.querySelector('main');
        const text = (main?.innerHTML ?? '').replace(/<[^>]+>/g, ' ');

        expect(text).not.toMatch(/\brecord\b/i);
        expect(text).not.toMatch(/\bbest\b/i);
        expect(text).not.toMatch(/1RM/i);
        expect(text).not.toMatch(/\bprogress\b/i);
        expect(text).not.toMatch(/percentage/i);
        expect(text).not.toMatch(/\btarget\b/i);
    });
});
