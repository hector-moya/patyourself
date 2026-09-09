import type * as InertiaReact from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

/** CoachLayout renders `<Head>` and the desktop rail reads `usePage()` —
 *  neither has a live Inertia app behind it in a component test, the same
 *  seam `session.test.tsx` stubs. */
const page = {
    url: '/occurrences/42/exercises/9',
    props: { unread_notifications_count: 0 },
};
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return { ...actual, Head: () => null, usePage: () => page };
});

import { show as showProgression } from '@/routes/training/progression';

import ExerciseScreen from './exercise';
import type { ExerciseData, ExerciseProps } from './exercise';

function exercise(overrides: Partial<ExerciseData> = {}): ExerciseData {
    return {
        id: 9,
        name: 'Bench press',
        target_sets: 3,
        target_reps: 10,
        instructions: [],
        image_path: null,
        ...overrides,
    };
}

function renderExercise(overrides: Partial<ExerciseProps> = {}) {
    const props: ExerciseProps = {
        occurrence_id: 42,
        exercise: exercise(),
        performed_sets: [],
        last: null,
        ...overrides,
    };

    return render(<ExerciseScreen {...props} />);
}

describe('ExerciseScreen', () => {
    /**
     * Killing mutation: hardcode the summary to a fixed string (or drop the
     * weight/date parts), e.g. always render just the reps. The rendered
     * text would then lack "60kg" or "2 Sep" — verified by direct mutation
     * and rerun.
     */
    it("renders last session's numbers when there is history", () => {
        renderExercise({
            last: {
                performed_at: '2026-09-02T09:00:00+00:00',
                sets: [
                    { reps: 10, weight: 60 },
                    { reps: 10, weight: 60 },
                    { reps: 8, weight: 60 },
                ],
            },
        });

        const line = screen.getByTestId('last-performance');

        expect(line).toHaveTextContent('60kg');
        expect(line).toHaveTextContent('10 / 10 / 8');
        expect(line).toHaveTextContent('2 Sep');
    });

    /**
     * `LastPerformance` returns null when there is no history, and the
     * screen must not render an empty or zeroed row for it.
     *
     * Killing mutation: render the summary container unconditionally (drop
     * the `last &&` guard), so a null `last` still renders an empty
     * `data-testid="last-performance"` node — `queryByTestId` would then
     * find it — verified by direct mutation and rerun.
     */
    it('renders no last-session row at all when there is no history', () => {
        renderExercise({ last: null });

        expect(
            screen.queryByTestId('last-performance'),
        ).not.toBeInTheDocument();
    });

    /**
     * Killing mutation: format a null first-set weight as "0kg" instead of
     * omitting it (e.g. `` `${weight ?? 0}kg` ``). The summary would then
     * contain "0kg" for a body-weight exercise — verified by direct mutation
     * and rerun.
     */
    it('shows a body-weight last session as blank weight, never 0', () => {
        renderExercise({
            last: {
                performed_at: '2026-09-02T09:00:00+00:00',
                sets: [{ reps: 12, weight: null }],
            },
        });

        const text = screen.getByTestId('last-performance').textContent ?? '';

        expect(text).not.toMatch(/0kg/);
        expect(text).not.toMatch(/\b0\b/);
    });

    /**
     * Killing mutation: skip rendering `exercise.instructions` entirely.
     * The imported step would then be absent from the page — verified by
     * direct mutation and rerun.
     */
    it('renders the imported instruction', () => {
        renderExercise({
            exercise: exercise({
                instructions: [
                    'Lower to the chest under control, press to lockout.',
                ],
            }),
        });

        expect(
            screen.getByText(
                'Lower to the chest under control, press to lockout.',
            ),
        ).toBeInTheDocument();
    });

    /**
     * v1 ships no exercise images at all — `image_path` is always null, so
     * this is the normal case, not an edge case. The screen must render
     * cleanly with nothing where a picture might one day go.
     *
     * Killing mutation: render `<img src={exercise.image_path} />`
     * unconditionally instead of guarding on it being non-null. React would
     * still render an `<img>` with no `src` (not throw), so the meaningful
     * assertion is that no `img` role exists at all — verified by direct
     * mutation and rerun.
     */
    it('renders without an image and does not error when the exercise has none', () => {
        expect(() =>
            renderExercise({ exercise: exercise({ image_path: null }) }),
        ).not.toThrow();

        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    /**
     * The back link must go through the generated Wayfinder helper for
     * `training.session.show`, not a hardcoded string.
     *
     * Killing mutation: hardcode the href to `/dashboard` (or any other
     * fixed string). The assertion below would then fail — verified by
     * direct mutation and rerun.
     */
    it('links back to the session screen through the generated route', () => {
        renderExercise({ occurrence_id: 42 });

        expect(screen.getByRole('link', { name: /back to session/i })).toHaveAttribute(
            'href',
            '/occurrences/42/session',
        );
    });

    /**
     * The screen's second door into its own history: a link to the
     * progression screen for this exercise, through the generated Wayfinder
     * helper rather than a hand-built URL.
     *
     * Killing mutation: drop the link from `exercise.tsx` entirely. There
     * would be no "Earlier sessions" link and this assertion fails —
     * verified by direct mutation and rerun.
     */
    it('links to the progression screen for this exercise, named as history rather than a judgement', () => {
        renderExercise({ exercise: exercise({ id: 9 }) });

        expect(
            screen.getByRole('link', { name: 'Earlier sessions' }),
        ).toHaveAttribute('href', showProgression.url(9));
    });

    /**
     * With no routine row (`target_sets` null) and nothing recorded yet,
     * `SetGrid`'s `pendingCount = max(targetSets - performedSets.length, 0)`
     * must still land on at least one open row — a screen whose entire
     * purpose is recording must never compute zero pending rows.
     *
     * Killing mutation: revert `exercise.target_sets ?? performedSets.length
     * + 1` back to `exercise.target_sets ?? performedSets.length` (drop the
     * `+ 1`). With `target_sets: null` and nothing recorded that computes
     * `0 - 0 = 0` and `set-row-open-1` is absent — verified by direct
     * mutation and rerun.
     */
    it('offers an open row when the exercise has no routine target and nothing recorded', () => {
        renderExercise({
            exercise: exercise({ target_sets: null, target_reps: null }),
            performed_sets: [],
        });

        expect(screen.getByTestId('set-row-open-1')).toBeInTheDocument();
    });

    /**
     * Same defect, reachable mid-session: two sets already recorded against
     * this occasion, then the exercise is dropped from the routine before
     * the third set. The extra row must still appear past what is already
     * recorded, not just on an otherwise-blank screen.
     *
     * Killing mutation: same as above — with the `+ 1` reverted, two
     * recorded sets and `target_sets: null` compute `0 - 2 = 0` (clamped)
     * pending rows and `set-row-open-3` is absent — verified by direct
     * mutation and rerun.
     */
    it('offers an open row when the exercise has no routine target and sets already recorded', () => {
        renderExercise({
            exercise: exercise({ target_sets: null, target_reps: null }),
            performed_sets: [
                { reps: 10, weight: 60 },
                { reps: 8, weight: 60 },
            ],
        });

        expect(screen.getByTestId('set-row-open-3')).toBeInTheDocument();
    });

    /**
     * The line this screen must not cross: record, never prescribe. Nothing
     * here may suggest a weight, name a record, cite a 1RM, show a
     * percentage, or call a trend progress.
     *
     * Killing mutation: add a line like "up 5kg from last time" or "On
     * track: 92% to target" anywhere in the render. Either turns one of
     * these assertions red — verified by direct mutation and rerun.
     */
    it('never suggests a weight, detects a record, or shows a percentage or trend', () => {
        const { container } = renderExercise({
            exercise: exercise({
                instructions: ['Lower to the chest under control, press to lockout.'],
            }),
            performed_sets: [{ reps: 10, weight: 60 }],
            last: {
                performed_at: '2026-09-02T09:00:00+00:00',
                sets: [
                    { reps: 10, weight: 60 },
                    { reps: 10, weight: 60 },
                    { reps: 8, weight: 60 },
                ],
            },
        });

        const text = container.textContent ?? '';

        expect(text).not.toMatch(/\bsuggest/i);
        expect(text).not.toMatch(/\brecord\b/i);
        expect(text).not.toMatch(/personal best|\bPR\b/);
        expect(text).not.toMatch(/1RM/i);
        expect(text).not.toMatch(/%/);
        expect(text).not.toMatch(/\bprogress\b/i);
        expect(text).not.toMatch(/\bstreak\b/i);
        expect(text).not.toMatch(/well done|congratulation/i);
        // "the number should go up": no comparison-to-last-time phrasing and
        // no directional/trend language anywhere near a weight.
        expect(text).not.toMatch(/from last time/i);
        expect(text).not.toMatch(/\btrend(ing)?\b/i);
        expect(text).not.toMatch(/\bup \d/i);
        expect(text).not.toMatch(/\+\d/);
    });
});
