import { Form } from '@inertiajs/react';
import { useState } from 'react';

import { Icon } from '@/patyourself/primitives';
import sets from '@/routes/occurrences/sets';

export interface SetGridPerformedSet {
    reps: number;
    /** Kilograms, or null for body weight — never zero. Zero is a weight;
     *  null is "not applicable", and the two must render differently. */
    weight: number | null;
}

export interface SetGridProps {
    occurrenceId: number;
    exerciseId: number;
    /** How many sets the routine targets. A set beyond it still records —
     *  see `RecordSet` — so `performedSets` longer than this simply leaves
     *  no open row rather than erroring. */
    targetSets: number;
    /** Already recorded against this occasion, in `set_number` order. */
    performedSets: SetGridPerformedSet[];
}

const CELL_GRID = 'grid grid-cols-[1fr_1fr_auto] items-center gap-3';
const INPUT_CLASS =
    'w-full min-w-0 rounded-md border border-border bg-background px-2 py-1.5 text-sm';

/**
 * The set-recording grid: one row per set, weight/reps/done.
 *
 * A set already recorded this occasion renders as a plain, settled row — see
 * `RecordSet`, the only writer of a `PerformedSet`: there is no update
 * endpoint, so a settled row has nothing left to edit. Everything after it is
 * a still-open row: a real weight/reps input pair plus a "done" control that
 * records it.
 *
 * Weight carries down from the row above as a *default*, never a target: the
 * common case is the same plate loaded three times running, and retyping it
 * three sets in a row is the friction that stops someone logging the third
 * one at all. It is deliberately one-directional — a row's default only ever
 * looks upward, at rows strictly above its own index, so typing into a lower
 * row can never reach back and change a row that already displays its own
 * value above it.
 *
 * The default is drawn only from *this session's own* rows — the nearest
 * open row above that the user has typed into, or (for the first open row)
 * the last set already recorded this occasion. It never reaches into
 * `LastPerformance`'s history: that read is informational, on screen so the
 * user does not have to remember last time's numbers, and pre-filling a
 * field from it would make it a suggested weight, which the spec rules out.
 * That line is not quoted from anywhere — it follows from "no suggested
 * weight" once you ask where a default could legitimately come from.
 */
export default function SetGrid({
    occurrenceId,
    exerciseId,
    targetSets,
    performedSets,
}: SetGridProps) {
    const pendingCount = Math.max(targetSets - performedSets.length, 0);
    const lastSettledWeight =
        performedSets.length > 0
            ? performedSets[performedSets.length - 1].weight
            : null;

    return (
        <div data-testid="set-grid" className="flex flex-col gap-2">
            <div className={`${CELL_GRID} text-xs text-muted-foreground`}>
                <span>weight</span>
                <span>reps</span>
                <span className="sr-only">done</span>
            </div>

            <div className="flex flex-col divide-y divide-border">
                {performedSets.map((set, index) => (
                    <SettledRow key={index} rowNumber={index + 1} set={set} />
                ))}

                {/* Keyed on how many sets are already settled: once a row is
                 *  recorded, the occurrence page reloads with one more entry
                 *  in `performedSets`, and this remount clears local drafts
                 *  rather than letting a stale row index bleed into the row
                 *  that has taken its place. */}
                <OpenRows
                    key={performedSets.length}
                    occurrenceId={occurrenceId}
                    exerciseId={exerciseId}
                    settledCount={performedSets.length}
                    pendingCount={pendingCount}
                    lastSettledWeight={lastSettledWeight}
                />
            </div>
        </div>
    );
}

function SettledRow({
    rowNumber,
    set,
}: {
    rowNumber: number;
    set: SetGridPerformedSet;
}) {
    return (
        <div
            data-testid={`set-row-settled-${rowNumber}`}
            className={`${CELL_GRID} py-1.5`}
        >
            <span data-testid={`set-weight-${rowNumber}`} className="text-sm text-foreground">
                {set.weight === null ? '' : `${set.weight}kg`}
            </span>
            <span data-testid={`set-reps-${rowNumber}`} className="text-sm text-foreground">
                {set.reps}
            </span>
            <Icon name="check" size={16} className="text-foreground" />
        </div>
    );
}

function OpenRows({
    occurrenceId,
    exerciseId,
    settledCount,
    pendingCount,
    lastSettledWeight,
}: {
    occurrenceId: number;
    exerciseId: number;
    settledCount: number;
    pendingCount: number;
    lastSettledWeight: number | null;
}) {
    // Keyed by the open row's own index among open rows (0-based). Absent
    // means "not yet touched by the user" — the row displays a computed
    // default rather than a value of its own.
    const [drafts, setDrafts] = useState<Record<number, string>>({});

    function defaultWeightFor(rowIndex: number): string {
        for (let above = rowIndex - 1; above >= 0; above -= 1) {
            if (drafts[above] !== undefined) {
                return drafts[above];
            }
        }

        return lastSettledWeight !== null ? String(lastSettledWeight) : '';
    }

    return (
        <>
            {Array.from({ length: pendingCount }, (_, rowIndex) => rowIndex).map(
                (rowIndex) => (
                    <OpenRow
                        key={rowIndex}
                        rowNumber={settledCount + rowIndex + 1}
                        occurrenceId={occurrenceId}
                        exerciseId={exerciseId}
                        weight={drafts[rowIndex] ?? defaultWeightFor(rowIndex)}
                        onWeightChange={(value) =>
                            setDrafts((prev) => ({ ...prev, [rowIndex]: value }))
                        }
                    />
                ),
            )}
        </>
    );
}

function OpenRow({
    rowNumber,
    occurrenceId,
    exerciseId,
    weight,
    onWeightChange,
}: {
    rowNumber: number;
    occurrenceId: number;
    exerciseId: number;
    weight: string;
    onWeightChange: (value: string) => void;
}) {
    return (
        <Form
            {...sets.store.form(occurrenceId)}
            options={{ preserveScroll: true }}
            className={`${CELL_GRID} py-1.5`}
            data-testid={`set-row-open-${rowNumber}`}
        >
            {({ processing, errors, submit }) => (
                <>
                    <input type="hidden" name="exercise_id" value={exerciseId} />

                    <span className="flex items-center gap-1">
                        <input
                            type="number"
                            name="weight"
                            step="0.01"
                            min={0}
                            inputMode="decimal"
                            aria-label={`weight for set ${rowNumber}`}
                            data-testid={`set-weight-input-${rowNumber}`}
                            value={weight}
                            onChange={(event) => onWeightChange(event.target.value)}
                            className={INPUT_CLASS}
                        />
                        <span className="text-xs text-muted-foreground">kg</span>
                    </span>

                    <input
                        type="number"
                        name="reps"
                        min={1}
                        inputMode="numeric"
                        aria-label={`reps for set ${rowNumber}`}
                        data-testid={`set-reps-input-${rowNumber}`}
                        className={INPUT_CLASS}
                    />

                    <input
                        type="checkbox"
                        aria-label={`mark set ${rowNumber} done`}
                        data-testid={`set-done-${rowNumber}`}
                        checked={false}
                        disabled={processing}
                        onChange={() => submit()}
                        className="size-5"
                    />

                    {(errors.reps ?? errors.weight) && (
                        <p className="col-span-3 text-xs text-destructive">
                            {errors.reps ?? errors.weight}
                        </p>
                    )}
                </>
            )}
        </Form>
    );
}
