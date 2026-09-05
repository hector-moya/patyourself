import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { WorkflowRecordProps, WorkflowRegistry } from './workflows';
import { WorkflowRecord } from './workflow-record';

function Surface({ occurrenceId, actionId }: WorkflowRecordProps) {
    return (
        <p data-testid="surface">
            {String(occurrenceId)}/{actionId}
        </p>
    );
}

function ThrowingSurface(): never {
    throw new Error('a broken recording surface');
}

const FAKE: WorkflowRegistry = {
    'spec-fake': { name: 'spec-fake', label: 'Spec fake', record: Surface },
    bare: { name: 'bare', label: 'Bare', record: null },
    broken: { name: 'broken', label: 'Broken', record: ThrowingSurface },
};

describe('WorkflowRecord', () => {
    it('draws the named workflow surface', () => {
        render(
            <WorkflowRecord
                workflow="spec-fake"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('7/3');
    });

    it('draws nothing for a plain loop', () => {
        const { container } = render(
            <WorkflowRecord
                workflow={null}
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing for a workflow the registry does not know', () => {
        const { container } = render(
            <WorkflowRecord
                workflow="gimnasio"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing for an inherited property name', () => {
        const { container } = render(
            <WorkflowRecord
                workflow="constructor"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws nothing for a registered workflow that records nothing', () => {
        const { container } = render(
            <WorkflowRecord
                workflow="bare"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('passes a null occurrence through to the surface', () => {
        render(
            <WorkflowRecord
                workflow="spec-fake"
                occurrenceId={null}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('null/3');
    });

    describe('when the registered surface throws', () => {
        let consoleError: ReturnType<typeof vi.spyOn>;

        beforeEach(() => {
            // React logs a caught render error to console.error even though the
            // boundary handles it. Silenced here only, so this is the one test
            // whose output is expected to be noisy rather than pristine.
            consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        });

        afterEach(() => {
            consoleError.mockRestore();
        });

        it('degrades to nothing rather than propagating past this component', () => {
            const { container } = render(
                <WorkflowRecord
                    workflow="broken"
                    occurrenceId={7}
                    actionId={3}
                    registry={FAKE}
                />,
            );

            expect(container).toBeEmptyDOMElement();
        });

        /**
         * The boundary is a class instance React can reuse across re-renders at
         * the same tree position. Without a key tied to `workflow`, a throw
         * caught once latches that instance forever: a later re-render at the
         * same position with a different, working workflow would still show
         * nothing, indistinguishable from the deliberate "plain loop" fallback.
         * Re-rendering the same element with a new `workflow` prop is what a
         * caller reusing this component across occasions actually does.
         */
        it('recovers when a later render at the same position names a different, working workflow', () => {
            const { rerender } = render(
                <WorkflowRecord
                    workflow="broken"
                    occurrenceId={7}
                    actionId={3}
                    registry={FAKE}
                />,
            );

            rerender(
                <WorkflowRecord
                    workflow="spec-fake"
                    occurrenceId={7}
                    actionId={3}
                    registry={FAKE}
                />,
            );

            expect(screen.getByTestId('surface')).toHaveTextContent('7/3');
        });
    });

    it('leaves a normally-rendering surface unaffected by the boundary wrapped around it', () => {
        render(
            <WorkflowRecord
                workflow="spec-fake"
                occurrenceId={7}
                actionId={3}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('7/3');
    });
});
