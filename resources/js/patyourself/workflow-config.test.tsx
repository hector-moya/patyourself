import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WorkflowConfig } from './workflow-config';
import type {
    WorkflowConfigProps,
    WorkflowConfigRow,
    WorkflowRegistry,
} from './workflows';

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

function Surface({ actionId, rows }: WorkflowConfigProps) {
    return (
        <p data-testid="surface">
            {actionId}/{rows.length}
        </p>
    );
}

function ThrowingSurface(): never {
    throw new Error('a broken configuration surface');
}

// `record: null` throughout: this file is about the config extension site, and
// a workflow that records nothing is an empty site rather than a special case.
// `workflow-record.test.tsx` is the mirror of this for the other one.
const FAKE: WorkflowRegistry = {
    'spec-fake': {
        name: 'spec-fake',
        label: 'Spec fake',
        config: Surface,
        record: null,
    },
    bare: { name: 'bare', label: 'Bare', config: null, record: null },
    broken: {
        name: 'broken',
        label: 'Broken',
        config: ThrowingSurface,
        record: null,
    },
};

describe('WorkflowConfig', () => {
    /**
     * Killing mutation: pass a hardcoded empty `rows` array to the surface
     * instead of the prop. The rendered "3/2" would read "3/0" — verified by
     * direct mutation and rerun.
     */
    it('draws the named workflow surface with the action and its rows', () => {
        render(
            <WorkflowConfig
                workflow="spec-fake"
                actionId={3}
                rows={[row({ id: 1 }), row({ id: 2 })]}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('3/2');
    });

    /**
     * The whole reason a plain loop is allowed to carry this slot at all: it
     * draws nothing, so the action layer reads exactly as it always has.
     *
     * Killing mutation: fall back to the first registered workflow when the
     * name is null. The surface would render for every plain loop in the app
     * — verified by direct mutation and rerun.
     */
    it('draws nothing for a plain loop', () => {
        const { container } = render(
            <WorkflowConfig
                workflow={null}
                actionId={3}
                rows={null}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    /**
     * Naming a workflow the registry does not know must never break a screen —
     * including `constructor`, because a plain object's lookup walks the
     * prototype chain and resolves it to a truthy inherited value. `scenes.ts`
     * and `workflows.ts` both record this trap; this is the third slot to hold
     * the rule.
     */
    it.each([['nonesuch'], ['constructor'], ['toString']])(
        'draws nothing for %s, which the registry does not know',
        (name) => {
            const { container } = render(
                <WorkflowConfig
                    workflow={name}
                    actionId={3}
                    rows={[row()]}
                    registry={FAKE}
                />,
            );

            expect(container).toBeEmptyDOMElement();
        },
    );

    it('draws nothing for a workflow that configures nothing', () => {
        const { container } = render(
            <WorkflowConfig
                workflow="bare"
                actionId={3}
                rows={[row()]}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    /**
     * Null rows and an empty array are different facts — "this loop has no
     * configuration surface" against "this action's routine is empty" — and
     * only the second one has anything to draw.
     *
     * Killing mutation: treat null as `[]` and render anyway. The surface
     * would appear for a loop the server never sent a routine for, which is
     * every plain loop — verified by direct mutation and rerun.
     */
    it('draws nothing when the server sent no rows at all, even for a registered workflow', () => {
        const { container } = render(
            <WorkflowConfig
                workflow="spec-fake"
                actionId={3}
                rows={null}
                registry={FAKE}
            />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('draws an empty routine for a registered workflow, which is not the same as none', () => {
        render(
            <WorkflowConfig
                workflow="spec-fake"
                actionId={3}
                rows={[]}
                registry={FAKE}
            />,
        );

        expect(screen.getByTestId('surface')).toHaveTextContent('3/0');
    });

    describe('when the configuration surface throws', () => {
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

        /**
         * Killing mutation: remove the error boundary and render the surface
         * directly. The throw propagates past this component, `render` itself
         * rejects, and the action layer it sits inside would go down with it —
         * verified by direct mutation and rerun.
         */
        it('degrades to nothing rather than propagating past this component', () => {
            const { container } = render(
                <WorkflowConfig
                    workflow="broken"
                    actionId={3}
                    rows={[row()]}
                    registry={FAKE}
                />,
            );

            expect(container).toBeEmptyDOMElement();
        });
    });
});
