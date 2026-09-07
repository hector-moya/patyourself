import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import SetGrid from './set-grid';

describe('SetGrid', () => {
    /**
     * Killing mutation: drop the default lookup entirely (every open row
     * starts blank with no fallback to the row above). Row 2's weight input
     * would then read "" instead of "60" — verified by direct mutation and
     * rerun.
     */
    it('carries weight down from the set above as a default', () => {
        render(
            <SetGrid
                occurrenceId={42}
                exerciseId={7}
                targetSets={3}
                performedSets={[]}
            />,
        );

        fireEvent.change(screen.getByTestId('set-weight-input-1'), {
            target: { value: '60' },
        });

        expect(screen.getByTestId('set-weight-input-2')).toHaveValue(60);
        expect(screen.getByTestId('set-weight-input-3')).toHaveValue(60);
    });

    /**
     * The asymmetry the brief calls out as the subtlest killing mutation:
     * "make it carry up as well". A naive implementation might mirror every
     * open row's weight into one shared piece of state (or write the typed
     * value directly into the rows above), so that editing set 2 would also
     * change what set 1 displays. The real rule is one-directional: a row's
     * default only looks at rows strictly above its own index, so set 1 can
     * never be reached by an edit made to set 2 or set 3.
     *
     * Killing mutation: make the default lookup bidirectional (or route all
     * rows through one shared "current weight" variable instead of a
     * per-row draft keyed by index). Set 1's assertion below would then fail
     * once set 2 is edited — verified by direct mutation and rerun.
     */
    it('editing a lower row does not change the row above it', () => {
        render(
            <SetGrid
                occurrenceId={42}
                exerciseId={7}
                targetSets={3}
                performedSets={[]}
            />,
        );

        fireEvent.change(screen.getByTestId('set-weight-input-1'), {
            target: { value: '60' },
        });

        // Set 3 has now inherited 60 from set 2, which itself inherited it
        // from set 1 — establishing the chain before we break it below.
        expect(screen.getByTestId('set-weight-input-3')).toHaveValue(60);

        fireEvent.change(screen.getByTestId('set-weight-input-2'), {
            target: { value: '75' },
        });

        // Set 1, strictly above the edited row, must be untouched.
        expect(screen.getByTestId('set-weight-input-1')).toHaveValue(60);
        // Set 3, strictly below, now inherits from its nearest row above —
        // set 2 — which has diverged to 75.
        expect(screen.getByTestId('set-weight-input-3')).toHaveValue(75);
    });

    /**
     * "It is a default, not a target — every field stays editable." Proven
     * by actually retyping a field that started on the carried-down default
     * and confirming the new value sticks, rather than merely asserting the
     * absence of a `disabled`/`readOnly` attribute.
     *
     * Killing mutation: render the carried-down weight value as a read-only
     * hint (e.g. `readOnly` once a default applies, or ignore `onChange` for
     * a defaulted field). The second assertion below would then still read
     * "60" instead of "45" — verified by direct mutation and rerun.
     */
    it('keeps every field editable, including one already showing a carried-down default', () => {
        render(
            <SetGrid
                occurrenceId={42}
                exerciseId={7}
                targetSets={2}
                performedSets={[{ reps: 10, weight: 60 }]}
            />,
        );

        const weightInput = screen.getByTestId('set-weight-input-2');
        const repsInput = screen.getByTestId('set-reps-input-2');

        // The first (and only) open row inherits its default from the
        // already-settled set above it.
        expect(weightInput).toHaveValue(60);
        expect(weightInput).not.toBeDisabled();
        expect(weightInput).not.toHaveAttribute('readonly');
        expect(repsInput).not.toBeDisabled();

        fireEvent.change(weightInput, { target: { value: '45' } });
        fireEvent.change(repsInput, { target: { value: '8' } });

        expect(weightInput).toHaveValue(45);
        expect(repsInput).toHaveValue(8);
    });

    /**
     * Reps never carry down — each set's actual reps vary set to set (that
     * is the whole reason "10 / 10 / 8" in the last-session summary looks
     * like that), so an open row's reps field starts blank regardless of
     * what a row above it holds.
     *
     * Killing mutation: default reps the same way weight is defaulted (carry
     * the value of the row above down into it). The second open row's reps
     * input would then read "10" instead of being empty — verified by direct
     * mutation and rerun.
     */
    it('never carries reps down between open rows', () => {
        render(
            <SetGrid
                occurrenceId={42}
                exerciseId={7}
                targetSets={2}
                performedSets={[]}
            />,
        );

        fireEvent.change(screen.getByTestId('set-reps-input-1'), {
            target: { value: '10' },
        });

        expect(screen.getByTestId('set-reps-input-2')).toHaveValue(null);
    });

    /**
     * Null means body weight — "not applicable" — and must never render as
     * "0kg": zero is a real weight, and a chart cannot tell the two apart
     * once it does.
     *
     * Killing mutation: default a null weight to 0 when formatting a settled
     * row (e.g. `` `${set.weight ?? 0}kg` ``). The cell would then read
     * "0kg" instead of being blank — verified by direct mutation and rerun.
     */
    it('shows a settled body-weight set as blank, never 0', () => {
        render(
            <SetGrid
                occurrenceId={42}
                exerciseId={7}
                targetSets={1}
                performedSets={[{ reps: 12, weight: null }]}
            />,
        );

        const cell = screen.getByTestId('set-weight-1');

        expect(cell).toHaveTextContent('');
        expect(cell.textContent).not.toMatch(/0/);
    });

    /**
     * Killing mutation: render `performedSets.length` settled rows without
     * capping the open-row count at `targetSets - performedSets.length`
     * (e.g. always render `targetSets` open rows regardless of how many are
     * already settled). A fourth open row would then appear alongside the
     * two already-settled sets on a 3-set target — verified by direct
     * mutation and rerun.
     */
    it('renders exactly the target number of rows once some sets are already settled', () => {
        render(
            <SetGrid
                occurrenceId={42}
                exerciseId={7}
                targetSets={3}
                performedSets={[
                    { reps: 10, weight: 60 },
                    { reps: 10, weight: 60 },
                ]}
            />,
        );

        expect(screen.getByTestId('set-row-settled-1')).toBeInTheDocument();
        expect(screen.getByTestId('set-row-settled-2')).toBeInTheDocument();
        expect(screen.getByTestId('set-row-open-3')).toBeInTheDocument();
        expect(screen.queryByTestId('set-row-open-4')).not.toBeInTheDocument();
    });
});
