import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import type { WorkflowRecordProps, WorkflowRegistry } from './workflows';
import { WorkflowRecord } from './workflow-record';

function Surface({ occurrenceId, actionId }: WorkflowRecordProps) {
    return (
        <p data-testid="surface">
            {String(occurrenceId)}/{actionId}
        </p>
    );
}

const FAKE: WorkflowRegistry = {
    'spec-fake': { name: 'spec-fake', label: 'Spec fake', record: Surface },
    bare: { name: 'bare', label: 'Bare', record: null },
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
});
