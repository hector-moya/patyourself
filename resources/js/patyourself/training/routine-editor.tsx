/**
 * The gym workflow's configuration surface: what an action's occasions are
 * meant to contain, one row per exercise, in the order they get worked.
 *
 * Registered at `WORKFLOWS.gym.config` and drawn by `WorkflowConfig` inside
 * the loop screen's action layer — an action's configuration belongs beside
 * the action, not on a screen of its own. A loop with no workflow draws
 * nothing here and its action layer reads exactly as it always has.
 *
 * Record, never prescribe. `target_sets` and `target_reps` are written here,
 * by the person, and everything downstream shows them back *because they wrote
 * them* — the session screen's dots and the exercise screen's "target 3 x 10"
 * are both quoting this, not recommending anything. Nothing on this screen
 * suggests a weight, and a routine row deliberately holds none: what was
 * actually lifted is a fact about an occasion, and lives on `PerformedSet`.
 *
 * The three writes go to `RoutineController`, which owns the authorization and
 * hands the writes to `App\Actions\Training`. Reordering posts the whole order
 * rather than one moved row: `ReorderRoutine` refuses a payload that does not
 * name every current row, so a partial order cannot be half-applied.
 */
import { Form, Link, router, useHttp } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/patyourself/primitives';
import type { WorkflowConfigProps, WorkflowConfigRow } from '@/patyourself/workflows';
import routine from '@/routes/actions/exercises';
import catalogue from '@/routes/training/exercises';
import { show as showProgression } from '@/routes/training/progression';

/** One row of `ExerciseCatalogueController::index`'s JSON. */
interface CatalogueMatch {
    id: number;
    name: string;
    category: string | null;
    equipment: string | null;
}

interface CatalogueResponse {
    exercises: CatalogueMatch[];
}

const FIELD_CLASS =
    'w-full rounded-md border border-border bg-background px-3 py-2 text-sm';

export default function RoutineEditor({ actionId, rows }: WorkflowConfigProps) {
    // One flag for the whole editor, not one per row: two rows reordered in
    // quick succession both compute their payload from the same unrefreshed
    // `rows` prop, so a per-row guard only blocks a second tap on the *same*
    // row and leaves the cross-row race wide open — see `RoutineRow.move()`.
    // The consequence is deliberate: an in-flight reorder disables every
    // row's arrows, not just the row that started it, because the payload
    // posted is the whole order, not one row's move.
    const [reordering, setReordering] = useState(false);

    return (
        <div data-testid={`routine-editor-${actionId}`} className="space-y-3">
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No exercises on this one yet.
                </p>
            ) : (
                <ul className="space-y-1">
                    {rows.map((row, index) => (
                        <RoutineRow
                            key={row.id}
                            actionId={actionId}
                            row={row}
                            rows={rows}
                            index={index}
                            reordering={reordering}
                            setReordering={setReordering}
                        />
                    ))}
                </ul>
            )}

            <AddExercise actionId={actionId} />
        </div>
    );
}

/**
 * One exercise on the routine, with the two controls that change its place and
 * the one that takes it off.
 *
 * Up/down rather than dragging: a drag surface would need a dependency this
 * project has not taken, and would leave the order unreachable by keyboard.
 *
 * `reordering` and `setReordering` are owned by `RoutineEditor`, not this
 * row — a flag scoped to one row cannot see a second row's move starting
 * while its own patch is still in flight, which is exactly the race this
 * guard exists to close.
 */
function RoutineRow({
    actionId,
    row,
    rows,
    index,
    reordering,
    setReordering,
}: {
    actionId: number;
    row: WorkflowConfigRow;
    rows: WorkflowConfigRow[];
    index: number;
    reordering: boolean;
    setReordering: (reordering: boolean) => void;
}) {
    // Scoped to this row, unlike `reordering`: an edit posts only this row's
    // two columns, so a second row's edit cannot invalidate it — the cross-row
    // race that forces `reordering` up to the editor does not exist here.
    const [editingTargets, setEditingTargets] = useState(false);

    /** The whole order with `index` moved by one step, as ReorderRoutine wants it. */
    function move(by: -1 | 1) {
        if (reordering) {
            return;
        }

        const reordered = rows.map((each) => each.id);
        const [moved] = reordered.splice(index, 1);
        reordered.splice(index + by, 0, moved);

        setReordering(true);

        router.patch(
            routine.reorder.url(actionId),
            { order: reordered },
            {
                preserveScroll: true,
                onFinish: () => setReordering(false),
            },
        );
    }

    return (
        <li
            data-testid={`routine-row-${row.id}`}
            className="flex items-center gap-2"
        >
            {row.exercise_name === null ? (
                <span
                    data-testid={`routine-row-name-${row.id}`}
                    className="min-w-0 flex-1 truncate text-sm text-foreground"
                >
                    This exercise is no longer in the catalogue
                </span>
            ) : (
                <Link
                    href={showProgression.url(row.exercise_id)}
                    data-testid={`routine-row-name-${row.id}`}
                    className="min-w-0 flex-1 truncate text-sm text-foreground underline underline-offset-2 hover:text-muted-foreground"
                >
                    {row.exercise_name}
                </Link>
            )}

            {editingTargets ? (
                <Form
                    {...routine.update.form([actionId, row.id])}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setEditingTargets(false)}
                    className="flex shrink-0 items-center gap-1"
                >
                    {({ processing, errors }) => (
                        <>
                            <label
                                htmlFor={`target-sets-${row.id}`}
                                className="sr-only"
                            >
                                Sets
                            </label>
                            <input
                                id={`target-sets-${row.id}`}
                                name="target_sets"
                                type="number"
                                min={1}
                                defaultValue={row.target_sets}
                                className="w-14 rounded-md border border-border bg-background px-1.5 py-0.5 text-xs"
                            />
                            <span aria-hidden="true" className="text-xs text-muted-foreground">
                                x
                            </span>
                            <label
                                htmlFor={`target-reps-${row.id}`}
                                className="sr-only"
                            >
                                Reps
                            </label>
                            <input
                                id={`target-reps-${row.id}`}
                                name="target_reps"
                                type="number"
                                min={1}
                                defaultValue={row.target_reps}
                                className="w-14 rounded-md border border-border bg-background px-1.5 py-0.5 text-xs"
                            />
                            {(errors.target_sets ?? errors.target_reps) && (
                                <p className="text-sm text-destructive">
                                    {errors.target_sets ?? errors.target_reps}
                                </p>
                            )}
                            <Button type="submit" variant="ghost" size="sm" disabled={processing}>
                                Save
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditingTargets(false)}
                            >
                                Cancel
                            </Button>
                        </>
                    )}
                </Form>
            ) : (
                <button
                    type="button"
                    data-testid={`routine-row-target-${row.id}`}
                    aria-label={`Edit targets for ${row.exercise_name ?? 'this exercise'}`}
                    onClick={() => setEditingTargets(true)}
                    className="shrink-0 font-mono text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    {row.target_sets} x {row.target_reps}
                </button>
            )}

            <button
                type="button"
                aria-label={`move ${row.exercise_name ?? 'this exercise'} up`}
                disabled={index === 0 || reordering}
                onClick={() => move(-1)}
                className="shrink-0 rounded-md border border-border px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground disabled:opacity-40"
            >
                ↑
            </button>
            <button
                type="button"
                aria-label={`move ${row.exercise_name ?? 'this exercise'} down`}
                disabled={index === rows.length - 1 || reordering}
                onClick={() => move(1)}
                className="shrink-0 rounded-md border border-border px-1.5 py-0.5 text-xs text-muted-foreground hover:text-foreground disabled:opacity-40"
            >
                ↓
            </button>

            {/* Removing a routine row keeps the exercise and every set already
             *  recorded against it — see RemoveRoutineExercise. The copy says
             *  so rather than letting "remove" imply otherwise. */}
            <Form
                {...routine.destroy.form([actionId, row.id])}
                options={{ preserveScroll: true }}
            >
                {({ processing }) => (
                    <Button
                        type="submit"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                    >
                        Remove
                    </Button>
                )}
            </Form>
        </li>
    );
}

/**
 * Adding an exercise: search the catalogue, choose one, say how many sets and
 * reps it is for.
 *
 * The catalogue is 876 imported rows plus the user's own additions, so the
 * picker queries `training.exercises.index` rather than holding the list —
 * `useHttp` because that is a standalone JSON request, not a page visit.
 */
function AddExercise({ actionId }: { actionId: number }) {
    const [term, setTerm] = useState('');
    const [chosen, setChosen] = useState<CatalogueMatch | null>(null);

    const search = useHttp<Record<string, never>, CatalogueResponse>();
    const matches = search.response?.exercises ?? [];

    function runSearch() {
        const trimmed = term.trim();

        if (trimmed === '') {
            return;
        }

        setChosen(null);
        search.get(catalogue.index.url({ query: { q: trimmed } }));
    }

    return (
        <details data-testid={`routine-add-${actionId}`}>
            <summary className="ds-label cursor-pointer">Add an exercise</summary>

            <div className="space-y-3 pt-3">
                {/* Not a <form>: this sits inside the loop screen, and a
                 *  nested form would be invalid markup and would submit the
                 *  wrong thing on Enter. The button and the Enter key both
                 *  route through runSearch instead. */}
                <div className="space-y-1">
                    <label htmlFor={`routine-search-${actionId}`} className="ds-label">
                        Search the catalogue
                    </label>
                    <div className="flex gap-2">
                        <input
                            id={`routine-search-${actionId}`}
                            type="search"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    runSearch();
                                }
                            }}
                            placeholder="bench, squat, row…"
                            className={FIELD_CLASS}
                        />
                        <Button
                            type="button"
                            variant="secondary"
                            size="sm"
                            onClick={runSearch}
                            disabled={search.processing}
                        >
                            Search
                        </Button>
                    </div>
                </div>

                {search.wasSuccessful && matches.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Nothing in the catalogue matches that.
                    </p>
                )}

                {matches.length > 0 && (
                    <ul data-testid="routine-search-results" className="space-y-1">
                        {matches.map((match) => (
                            <li key={match.id}>
                                <button
                                    type="button"
                                    onClick={() => setChosen(match)}
                                    aria-pressed={chosen?.id === match.id}
                                    className="w-full rounded-md border border-border px-2 py-1 text-left text-sm hover:bg-accent/40 aria-pressed:border-foreground/30 aria-pressed:bg-accent/40"
                                >
                                    {match.name}
                                    {match.equipment !== null && (
                                        <span className="text-muted-foreground">
                                            {' · '}
                                            {match.equipment}
                                        </span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {chosen !== null && (
                    <Form
                        {...routine.store.form(actionId)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            setChosen(null);
                            setTerm('');
                        }}
                        className="space-y-3"
                        data-testid="routine-add-form"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="exercise_id"
                                    value={chosen.id}
                                />

                                <p className="text-sm text-foreground">
                                    {chosen.name}
                                </p>

                                <div className="flex gap-3">
                                    <div className="space-y-1">
                                        <label
                                            htmlFor={`routine-sets-${actionId}`}
                                            className="ds-label"
                                        >
                                            Sets
                                        </label>
                                        <input
                                            id={`routine-sets-${actionId}`}
                                            name="target_sets"
                                            type="number"
                                            min={1}
                                            defaultValue={3}
                                            inputMode="numeric"
                                            className="w-20 rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                    <div className="space-y-1">
                                        {/* A single number, not a range: v1
                                         *  says "10", not "8-12" — see
                                         *  ActionExercise for why that is
                                         *  chosen rather than overlooked. */}
                                        <label
                                            htmlFor={`routine-reps-${actionId}`}
                                            className="ds-label"
                                        >
                                            Reps
                                        </label>
                                        <input
                                            id={`routine-reps-${actionId}`}
                                            name="target_reps"
                                            type="number"
                                            min={1}
                                            defaultValue={10}
                                            inputMode="numeric"
                                            className="w-20 rounded-md border border-border bg-background px-3 py-2 text-sm"
                                        />
                                    </div>
                                </div>

                                {(errors.exercise_id ??
                                    errors.target_sets ??
                                    errors.target_reps) && (
                                    <p className="text-sm text-destructive">
                                        {errors.exercise_id ??
                                            errors.target_sets ??
                                            errors.target_reps}
                                    </p>
                                )}

                                <Button type="submit" disabled={processing}>
                                    Add to the routine
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </details>
    );
}
