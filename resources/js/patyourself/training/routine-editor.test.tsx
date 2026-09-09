import type * as InertiaReact from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/** What each submitted form actually sent: its action, method and fields. */
const submissions: {
    action?: string;
    method?: string;
    fields: Record<string, string>;
}[] = [];

/** Every `router.patch` the editor issued: url and payload. */
const patches: { url: string; data: unknown }[] = [];

/** Each patch's own `onFinish`, captured so a test can settle it on demand. */
const finishers: (() => void)[] = [];

/** What the stubbed catalogue search will answer with, and what it was asked. */
const searches: string[] = [];
let searchResponse: { exercises: unknown[] } | null = null;

/**
 * `Form`, `router` and `useHttp` are all stood in for so this renders without
 * a live server behind it — the same seam `gym-record.test.tsx` and
 * `set-grid.test.tsx` stub. `Form` renders a real `<form>` and serialises its
 * own fields on submit; `useHttp` records the URL it was asked for and hands
 * back whatever the test staged.
 */
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof InertiaReact>();

    return {
        ...actual,
        router: {
            ...actual.router,
            patch: (
                url: string,
                data: unknown,
                options?: { onFinish?: () => void },
            ) => {
                patches.push({ url, data });

                if (options?.onFinish) {
                    finishers.push(options.onFinish);
                }
            },
        },
        useHttp: () => ({
            processing: false,
            wasSuccessful: searchResponse !== null,
            response: searchResponse,
            get: (url: string) => {
                searches.push(url);

                return Promise.resolve(searchResponse);
            },
        }),
        Form: (props: {
            action?: string;
            method?: string;
            options?: unknown;
            onSuccess?: () => void;
            children:
                | ((props: {
                      processing: boolean;
                      errors: Record<string, string>;
                  }) => React.ReactNode)
                | React.ReactNode;
            [key: string]: unknown;
        }) => {
            const { action, method, children } = props;

            // Everything else rides through to the DOM, minus Inertia's own
            // props — `options` and `onSuccess` are not form attributes and
            // React warns if they reach one. Filtered rather than destructured
            // into discards, because a named binding nothing reads is a lint
            // error.
            const rest = Object.fromEntries(
                Object.entries(props).filter(
                    ([key]) =>
                        ![
                            'action',
                            'method',
                            'children',
                            'options',
                            'onSuccess',
                        ].includes(key),
                ),
            );

            return (
                <form
                    action={action}
                    method={method}
                    onSubmit={(event) => {
                        event.preventDefault();

                        submissions.push({
                            action,
                            method,
                            fields: Object.fromEntries(
                                Array.from(
                                    new FormData(event.currentTarget).entries(),
                                ).map(([key, value]) => [key, String(value)]),
                            ),
                        });
                    }}
                    {...rest}
                >
                    {typeof children === 'function'
                        ? children({ processing: false, errors: {} })
                        : children}
                </form>
            );
        },
    };
});

import type { WorkflowConfigRow } from '@/patyourself/workflows';
import { show as showProgression } from '@/routes/training/progression';

import RoutineEditor from './routine-editor';

function row(overrides: Partial<WorkflowConfigRow> = {}): WorkflowConfigRow {
    return {
        id: 1,
        exercise_id: 9,
        exercise_name: 'Barbell Back Squat',
        position: 1,
        target_sets: 5,
        target_reps: 5,
        ...overrides,
    };
}

/** Squat at position 1, row at 2, press at 3 — ids deliberately scrambled
 *  against that order so an id sort would be visible. */
const THREE_ROWS: WorkflowConfigRow[] = [
    row({
        id: 30,
        exercise_id: 1,
        exercise_name: 'Barbell Back Squat',
        position: 1,
    }),
    row({ id: 10, exercise_id: 2, exercise_name: 'Barbell Row', position: 2 }),
    row({
        id: 20,
        exercise_id: 3,
        exercise_name: 'Overhead Press',
        position: 3,
    }),
];

describe('RoutineEditor', () => {
    beforeEach(() => {
        submissions.length = 0;
        patches.length = 0;
        finishers.length = 0;
        searches.length = 0;
        searchResponse = null;
    });

    /**
     * The server sends the routine already in position order, so what this
     * owes is to render the array as given. The ids ascend in neither the
     * given order nor reverse of it, so an id sort would be visible.
     *
     * Killing mutation: sort `rows` by `id` before rendering. The names would
     * come back row, press, squat and this assertion fails — verified by
     * direct mutation and rerun.
     */
    it('lists the routine in the order the server sent it, with each row’s own target', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        const names = screen
            .getAllByTestId(/^routine-row-name-/)
            .map((element) => element.textContent);

        expect(names).toEqual([
            'Barbell Back Squat',
            'Barbell Row',
            'Overhead Press',
        ]);
        expect(screen.getByTestId('routine-row-target-30')).toHaveTextContent(
            '5 x 5',
        );
    });

    /**
     * The routine editor is the surface that works with no session running,
     * so it is the row name's second door into that exercise's progression
     * screen — through the generated Wayfinder helper, never a hand-built
     * URL.
     *
     * Killing mutation: link the row unconditionally, regardless of
     * `exercise_name`. This test alone would still pass; it is the null-name
     * test below that catches that mutation.
     */
    it('links a row’s name to that exercise’s progression screen when the catalogue still knows it', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        const link = screen.getByTestId('routine-row-name-30');

        expect(link.tagName).toBe('A');
        expect(link).toHaveAttribute('href', showProgression.url(1));
        expect(link).toHaveTextContent('Barbell Back Squat');
    });

    /**
     * A row whose exercise dropped out of the catalogue has nothing to link
     * to — `exercise_name` is null precisely because the row's exercise is
     * unresolvable, so there is no history a progression link could show.
     *
     * Killing mutation: link the row unconditionally regardless of
     * `exercise_name`. The row would render an anchor here and this
     * assertion fails — verified by direct mutation and rerun.
     */
    it('renders a row with no catalogue name as plain text, with no link', () => {
        render(
            <RoutineEditor
                actionId={7}
                rows={[row({ id: 40, exercise_id: 4, exercise_name: null })]}
            />,
        );

        const nameElement = screen.getByTestId('routine-row-name-40');

        expect(nameElement.tagName).not.toBe('A');
        expect(nameElement).toHaveTextContent(
            'This exercise is no longer in the catalogue',
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    /**
     * "An action with no template logs exactly as it does today. The tracker
     * is additive." An empty routine is an ordinary state, not an error, and
     * says so plainly rather than rendering a hollow list.
     */
    it('says so plainly when the routine is empty', () => {
        render(<RoutineEditor actionId={7} rows={[]} />);

        expect(
            screen.getByText('No exercises on this one yet.'),
        ).toBeInTheDocument();
        expect(screen.queryAllByTestId(/^routine-row-name-/)).toHaveLength(0);
    });

    /**
     * `ReorderRoutine` refuses a payload that does not name every current row,
     * so a move has to post the whole order — and it has to be the order after
     * the move, not before it.
     *
     * Killing mutation: send `rows.map((r) => r.id)` untouched (the order
     * before the move). The payload would read [30, 10, 20] instead of
     * [10, 30, 20] and this assertion fails — verified by direct mutation and
     * rerun.
     */
    it('moving a row up posts the whole order, with that row moved', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'move Barbell Row up' }),
        );

        expect(patches).toHaveLength(1);
        expect(patches[0].url).toBe('/actions/7/exercises/reorder');
        expect(patches[0].data).toEqual({ order: [10, 30, 20] });
    });

    /**
     * Asserted on the *middle* row on purpose. Moving the first row down and
     * moving it up are not distinguishable by their payload, so a direction
     * bug would hide there.
     *
     * Killing mutation: splice the moved row in at `index - 1` regardless of
     * direction, so down behaves as up. Moving row 10 down gives [10, 30, 20]
     * instead of [30, 20, 10] — verified by direct mutation and rerun.
     */
    it('moving a row down posts the whole order, with that row moved the other way', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'move Barbell Row down' }),
        );

        expect(patches).toHaveLength(1);
        expect(patches[0].data).toEqual({ order: [30, 20, 10] });
    });

    /**
     * Two fast reorders would both compute their order from the same stale
     * `rows` prop, so the second `router.patch` would overwrite the first and
     * the first move would vanish — worse than an error, because nothing
     * would say so.
     *
     * Killing mutation: drop `setReordering(true)` from `move()`, so the
     * guard never engages. Both clicks would reach `router.patch` and this
     * assertion fails — verified by direct mutation and rerun. (Dropping only
     * the early return, or only the buttons' `|| reordering`, survives here:
     * React refuses to dispatch `click` to a `disabled` native button, so the
     * other mechanism alone still blocks the second tap — the two are
     * deliberately redundant rather than each covering a distinct case.)
     */
    it('does not fire a second reorder while the first is still in flight', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        const downButton = screen.getByRole('button', {
            name: 'move Barbell Row down',
        });

        fireEvent.click(downButton);
        fireEvent.click(downButton);

        expect(patches).toHaveLength(1);
    });

    /**
     * The cross-row case the guard above does not by itself prove: clicking
     * row A's ↓ and then, before that round trip resolves, row B's ↑. Both
     * clicks compute their payload from the same unrefreshed `rows` prop, so
     * if each row tracked its own `reordering` flag, both patches would
     * fire and the last to arrive would silently overwrite the first —
     * losing a move one row over from the row that was guarded.
     *
     * One shared flag closes this completely, and does so on purpose at the
     * cost of disabling every row's arrows while any one reorder is in
     * flight — the payload posted is the whole order, not one row's move,
     * so there is no such thing as an unrelated row's reorder here.
     *
     * Killing mutation: scope `reordering`/`setReordering` back to a
     * `useState` inside `RoutineRow` instead of `RoutineEditor`. Row B's
     * button would then read its own, still-`false` flag and dispatch a
     * second `router.patch` — verified by direct mutation and rerun, raw
     * output captured in the task report.
     */
    it('does not fire a second reorder for a different row while the first is still in flight', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        fireEvent.click(
            screen.getByRole('button', { name: 'move Barbell Back Squat down' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'move Overhead Press up' }),
        );

        expect(patches).toHaveLength(1);
    });

    /**
     * A refused reorder still has to hand the row back — `onFinish` rather
     * than `onSuccess`, or a single validation failure would leave the
     * controls frozen until the page reloads.
     *
     * Killing mutation: settle the guard on `onSuccess` instead of
     * `onFinish`. The mock only ever pushes onto `finishers` from
     * `options.onFinish`, so there is nothing to capture and
     * `expect(finishers).toHaveLength(1)` fails first, before this test
     * ever gets to invoking a callback or checking the button — verified by
     * direct mutation and rerun.
     */
    it('re-enables the controls once the reorder finishes', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        const downButton = screen.getByRole('button', {
            name: 'move Barbell Row down',
        });

        fireEvent.click(downButton);

        expect(downButton).toBeDisabled();
        expect(finishers).toHaveLength(1);

        act(() => {
            finishers[0]();
        });

        expect(downButton).not.toBeDisabled();
    });

    /**
     * There is nowhere above the first row or below the last, and offering a
     * control that would post an order the server refuses is worse than not
     * offering it.
     *
     * Killing mutation: drop both `disabled` expressions. The first row's "up"
     * becomes enabled and this assertion fails — verified by direct mutation
     * and rerun.
     */
    it('cannot move the first row up or the last row down', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        expect(
            screen.getByRole('button', { name: 'move Barbell Back Squat up' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'move Overhead Press down' }),
        ).toBeDisabled();
    });

    /**
     * The URL carries `?_method=DELETE`: Wayfinder's form variant spoofs the
     * verb, because a browser form can only ever be GET or POST. That is part
     * of the contract with `RoutineController::destroy` and is asserted rather
     * than trimmed away.
     *
     * Killing mutation: point the remove form at `routine.destroy.form(actionId)`
     * with the action id alone. The URL would lose the row segment and the
     * server could not tell which row to drop — verified by direct mutation
     * and rerun.
     */
    it('removing a row posts to that row’s own destroy route', () => {
        render(<RoutineEditor actionId={7} rows={THREE_ROWS} />);

        const rowElement = screen.getByTestId('routine-row-10');

        fireEvent.submit(
            within(rowElement)
                .getByRole('button', { name: 'Remove' })
                .closest('form')!,
        );

        expect(submissions).toHaveLength(1);
        expect(submissions[0].action).toBe(
            '/actions/7/exercises/10?_method=DELETE',
        );
    });

    /**
     * The catalogue is 876 rows plus the user's own, so the picker queries the
     * search seam rather than holding the list.
     *
     * Killing mutation: send the raw `term` instead of the trimmed one, or
     * drop the query parameter entirely. The recorded URL would not carry
     * `q=bench` and this assertion fails — verified by direct mutation and
     * rerun.
     */
    it('searching the catalogue asks the search route for the typed term', () => {
        render(<RoutineEditor actionId={7} rows={[]} />);

        fireEvent.change(screen.getByLabelText('Search the catalogue'), {
            target: { value: '  bench  ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(searches).toEqual(['/exercises?q=bench']);
    });

    /** An empty box has nothing to ask about, and asking would return the
     *  first twenty rows of the catalogue for no reason. */
    it('does not search on an empty box', () => {
        render(<RoutineEditor actionId={7} rows={[]} />);

        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        expect(searches).toEqual([]);
    });

    /**
     * Two rows in this catalogue are called "Bench Press" and differ only by
     * equipment, so the result has to show it or the choice is a coin flip.
     *
     * Killing mutation: render `match.name` alone. The equipment would be
     * absent from both options and this assertion fails — verified by direct
     * mutation and rerun.
     */
    it('shows enough of each match to tell two of the same name apart', () => {
        searchResponse = {
            exercises: [
                {
                    id: 1,
                    name: 'Bench Press',
                    category: 'strength',
                    equipment: 'barbell',
                },
                {
                    id: 2,
                    name: 'Bench Press',
                    category: 'strength',
                    equipment: 'dumbbell',
                },
            ],
        };

        render(<RoutineEditor actionId={7} rows={[]} />);

        const results = screen.getByTestId('routine-search-results');

        expect(within(results).getByText(/barbell/)).toBeInTheDocument();
        expect(within(results).getByText(/dumbbell/)).toBeInTheDocument();
    });

    /**
     * Killing mutation: submit `exercise_id` from the first match rather than
     * the chosen one. Choosing the dumbbell row would still send id 1 and this
     * assertion fails — verified by direct mutation and rerun.
     */
    it('adding the chosen exercise posts its id with the sets and reps written for it', () => {
        searchResponse = {
            exercises: [
                {
                    id: 1,
                    name: 'Bench Press',
                    category: 'strength',
                    equipment: 'barbell',
                },
                {
                    id: 2,
                    name: 'Bench Press',
                    category: 'strength',
                    equipment: 'dumbbell',
                },
            ],
        };

        render(<RoutineEditor actionId={7} rows={[]} />);

        fireEvent.click(
            within(screen.getByTestId('routine-search-results')).getByText(
                /dumbbell/,
            ),
        );

        fireEvent.change(screen.getByLabelText('Sets'), {
            target: { value: '4' },
        });
        fireEvent.change(screen.getByLabelText('Reps'), {
            target: { value: '12' },
        });

        fireEvent.submit(screen.getByTestId('routine-add-form'));

        expect(submissions).toHaveLength(1);
        expect(submissions[0].action).toBe('/actions/7/exercises');
        expect(submissions[0].method).toBe('post');
        expect(submissions[0].fields).toEqual({
            exercise_id: '2',
            target_sets: '4',
            target_reps: '12',
        });
    });

    /**
     * Record, never prescribe. The editor writes what the user asked for and
     * nothing else — no weight anywhere on it, because what was actually
     * lifted is a fact about an occasion and lives on `PerformedSet`.
     *
     * Asserted on the rendered output rather than the source, so a label added
     * later is caught rather than only a variable name.
     */
    it('offers nothing to write a weight into, and names no record or trend', () => {
        searchResponse = {
            exercises: [
                {
                    id: 1,
                    name: 'Bench Press',
                    category: 'strength',
                    equipment: 'barbell',
                },
            ],
        };

        const { container } = render(
            <RoutineEditor actionId={7} rows={THREE_ROWS} />,
        );

        fireEvent.click(
            within(screen.getByTestId('routine-search-results')).getByText(
                /barbell/,
            ),
        );

        expect(screen.queryByLabelText(/weight/i)).not.toBeInTheDocument();
        expect(container.textContent).not.toMatch(
            /weight|kg|personal best|record|1rm|progress|%/i,
        );
    });
});
