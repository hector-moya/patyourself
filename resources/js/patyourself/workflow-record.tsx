/**
 * Where a workflow draws what happened during an occasion.
 *
 * Every screen that logs an outcome renders this above its verdict controls,
 * and for a plain loop it draws nothing at all — which is every loop today.
 * That is the fallback: a name the registry does not know is the same as no
 * name, and neither can leave a blank screen behind, because the verdict
 * controls are not this component's to remove.
 *
 * Deliberately rendered *outside* the verdict form. Recording does not log: a
 * record written here creates nothing, and the verdict is pressed separately,
 * by a person, always. Inputs living inside that form would submit with the
 * outcome and quietly join the two.
 */
import type { WorkflowRegistry } from '@/patyourself/workflows';
import { WORKFLOWS, workflowFor } from '@/patyourself/workflows';

interface WorkflowRecordSlotProps {
    /** The name stored on the loop. Null for a plain loop. */
    workflow: string | null;
    occurrenceId: number | null;
    actionId: number;
    /** Injectable so a test can route without a workflow being shipped. */
    registry?: WorkflowRegistry;
}

export function WorkflowRecord({
    workflow,
    occurrenceId,
    actionId,
    registry = WORKFLOWS,
}: WorkflowRecordSlotProps) {
    const spec = workflowFor(workflow, registry);

    if (spec === null || spec.record === null) {
        return null;
    }

    const Record = spec.record;

    return <Record occurrenceId={occurrenceId} actionId={actionId} />;
}
