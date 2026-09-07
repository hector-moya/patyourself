import type * as InertiaReact from '@inertiajs/react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

/** CoachLayout renders `<Head>` and the desktop rail reads `usePage()` —
 *  neither has a live Inertia app behind it in a component test, the same
 *  seam `dashboard.test.tsx` stubs. */
const page = { url: '/occurrences/42/session', props: { unread_notifications_count: 0 } };
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import Session from './session';
import type { SessionExerciseData, SessionProps } from './session';

function exercise(
    overrides: Partial<SessionExerciseData> = {},
): SessionExerciseData {
    return {
        id: 1,
        name: 'Bench press',
        target_sets: 3,
        target_reps: 10,
        performed_count: 0,
        ...overrides,
    };
}

function renderSession(overrides: Partial<SessionProps> = {}) {
    const props: SessionProps = {
        occurrence_id: 42,
        action_id: 7,
        action_title: 'Upper A',
        scheduled_for: '2026-09-10T09:00:00+00:00',
        exercises: [],
        ...overrides,
    };

    return render(<Session {...props} />);
}

describe('Session', () => {
    /**
     * Killing mutation: hardcode `performed_count` filling to `0` regardless
     * of the prop (e.g. `Array.from({ length: target }, () => false)`).
     * Bench press's dots then read 0-of-3 filled instead of 2-of-3, and this
     * test's dot-count assertion fails — verified by direct mutation and
     * rerun.
     */
    it('renders exercises in position order with target sets/reps and one dot per target set, filled to the number recorded', () => {
        renderSession({
            exercises: [
                exercise({
                    id: 1,
                    name: 'Bench press',
                    target_sets: 3,
                    target_reps: 10,
                    performed_count: 2,
                }),
                exercise({
                    id: 2,
                    name: 'Barbell row',
                    target_sets: 4,
                    target_reps: 8,
                    performed_count: 0,
                }),
            ],
        });

        const names = screen
            .getAllByTestId(/^exercise-row-/)
            .map((row) => within(row).getByTestId('exercise-name').textContent);

        // Position order, not insertion or id order in reverse or by name.
        expect(names).toEqual(['Bench press', 'Barbell row']);

        expect(screen.getByTestId('exercise-target-1')).toHaveTextContent(
            '3 x 10',
        );
        expect(screen.getByTestId('exercise-target-2')).toHaveTextContent(
            '4 x 8',
        );

        const benchDots = within(
            screen.getByTestId('exercise-dots-1'),
        ).getAllByTestId('set-dot');
        expect(benchDots).toHaveLength(3);
        expect(
            benchDots.filter((dot) => dot.getAttribute('data-filled') === 'true'),
        ).toHaveLength(2);

        const rowDots = within(
            screen.getByTestId('exercise-dots-2'),
        ).getAllByTestId('set-dot');
        expect(rowDots).toHaveLength(4);
        expect(
            rowDots.filter((dot) => dot.getAttribute('data-filled') === 'true'),
        ).toHaveLength(0);
    });

    /**
     * Task 7 builds the exercise screen; this task only has to leave a real
     * link in place. Killing mutation: render the row in a plain `<div>`
     * instead of wrapping it in a `Link` — the row would no longer expose an
     * `href` at all, and `getByRole('link', ...)` would fail to find it —
     * verified by direct mutation and rerun.
     */
    it('tapping an exercise navigates to the exercise screen', () => {
        renderSession({
            occurrence_id: 42,
            exercises: [exercise({ id: 9, name: 'Lat pulldown' })],
        });

        const link = screen.getByRole('link', { name: /lat pulldown/i });

        expect(link.getAttribute('href')).toBe('/occurrences/42/exercises/9');
    });

    /**
     * Killing mutation: point the verdict form at the action-keyed endpoint
     * (`/actions/${actionId}/logs`), the same fallback the dashboard uses for
     * an anchored occasion with no slot yet. This screen only ever exists for
     * an occurrence that already exists, so it must always use the occurrence
     * route — verified by direct mutation and rerun.
     */
    it('posts the verdict to the occurrence route', () => {
        renderSession({ occurrence_id: 99 });

        expect(screen.getByTestId('session-verdict-form')).toHaveAttribute(
            'action',
            '/occurrences/99/logs',
        );
    });

    /**
     * "An action with no template logs exactly as it does today. The tracker
     * is additive." Killing mutation: drop the `exercises.length === 0`
     * guard around the tracker section, so an empty routine still renders the
     * (empty) tracker container — `queryByTestId('exercise-tracker')` then
     * finds it — verified by direct mutation and rerun.
     */
    it('renders the plain verdict controls and no tracker for an action with no routine', () => {
        renderSession({ exercises: [] });

        expect(screen.queryByTestId('exercise-tracker')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Did it')).toBeInTheDocument();
        expect(screen.getByLabelText('Did not hold')).toBeInTheDocument();
        expect(screen.getByLabelText('Never happened')).toBeInTheDocument();
    });

    /**
     * The reason field is independent of the tracker: pressing Missed still
     * asks for it, exactly as the dashboard and catch-up screens already do,
     * and entering sets plays no part in the decision either way.
     *
     * Killing mutation: change the reason field's guard from
     * `outcome === 'failed'` to `outcome !== null`, so it appears for every
     * selected outcome instead of only a failure. Selecting "Never happened"
     * would then also show it, and this test's second assertion fails —
     * verified by direct mutation and rerun.
     */
    it('asks for a reason only when the strategy did not hold', () => {
        renderSession();

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Did not hold'));
        expect(
            screen.getByLabelText(/what happened, in your words/i),
        ).toBeInTheDocument();
    });

    it('asks for no reason when the occasion never happened', () => {
        renderSession();

        fireEvent.click(screen.getByLabelText('Never happened'));

        expect(
            screen.queryByLabelText(/what happened, in your words/i),
        ).not.toBeInTheDocument();
    });

    /**
     * The line this screen must not cross: record, never prescribe. Nothing
     * here may suggest a weight, name a record, or show a percentage — target
     * sets/reps are shown only because the user wrote them.
     *
     * Killing mutation: add a weight readout (e.g. "62.5 kg") beside a dot, or
     * a percentage-styled trend note. Either turns this assertion red —
     * verified by direct mutation and rerun.
     */
    it('never suggests a weight, names a record, or shows a percentage', () => {
        const { container } = renderSession({
            exercises: [
                exercise({ id: 1, name: 'Bench press', performed_count: 3 }),
            ],
        });

        const text = container.textContent ?? '';

        expect(text).not.toMatch(/\bkg\b|\blbs?\b|\bweight\b/i);
        expect(text).not.toMatch(/\brecord\b|\bpersonal best\b|\bPR\b/);
        expect(text).not.toMatch(/%/);
        expect(text).not.toMatch(/\bsuggest/i);
    });
});
