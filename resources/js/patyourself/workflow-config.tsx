/**
 * Where a workflow draws how an action is configured — what its occasions are
 * meant to contain.
 *
 * The sibling of `WorkflowRecord`, one extension site over. That one hangs off
 * an occasion and draws what happened; this one hangs off an action and draws
 * the standing prescription. `config/workflows.php` draws the same line on the
 * server: gym's `config` is keyed to `actions`, its `record` to `occurrences`.
 *
 * For a plain loop it draws nothing at all — which is every loop today, and
 * the ordinary case forever. A name the registry does not know is the same as
 * no name, and neither can leave a broken action layer behind: the action
 * layer's own controls are not this component's to remove. A registered
 * surface that throws while rendering cannot take them down either — it is
 * wrapped in an error boundary whose fallback is the same "draw nothing", so a
 * broken module degrades to the plain action layer.
 *
 * Deliberately rendered *outside* the action layer's own add-an-action form,
 * for the same reason `WorkflowRecord` sits outside the verdict form: a
 * configuration write is its own act, and inputs living inside that form would
 * submit with it and quietly join the two.
 */
import { Component } from 'react';
import type { ReactNode } from 'react';

import type { WorkflowConfigRow, WorkflowRegistry } from '@/patyourself/workflows';
import { WORKFLOWS, configSurfaceFor } from '@/patyourself/workflows';

interface WorkflowConfigSlotProps {
    /** The name stored on the loop. Null for a plain loop. */
    workflow: string | null;
    actionId: number;
    /**
     * The action's current configuration, or null when the loop does not
     * configure actions at all. Null and `[]` mean different things — "this
     * loop has no configuration surface" against "this action's routine is
     * empty" — and the server sends them apart for exactly that reason.
     */
    rows: WorkflowConfigRow[] | null;
    /** Injectable so a test can route without a workflow being shipped. */
    registry?: WorkflowRegistry;
}

interface ConfigBoundaryState {
    hasThrown: boolean;
}

/**
 * Catches a throw from the registered configuration surface and renders
 * nothing, rather than letting it propagate and take the action layer with it.
 * A class component because React exposes error boundaries only through
 * `static getDerivedStateFromError` — there is no hook equivalent.
 *
 * Rendered below with `key={workflow}`, for the reason `WorkflowRecord`'s own
 * boundary records at length: without a key tied to what it guards, React
 * reuses the instance across re-renders at that position and `hasThrown` never
 * clears, so the boundary stays latched even once a different, working
 * workflow occupies the position — indistinguishable from the deliberate
 * "plain loop draws nothing" fallback while meaning the opposite thing.
 */
class WorkflowConfigBoundary extends Component<{ children: ReactNode }, ConfigBoundaryState> {
    state: ConfigBoundaryState = { hasThrown: false };

    static getDerivedStateFromError(): ConfigBoundaryState {
        return { hasThrown: true };
    }

    render() {
        if (this.state.hasThrown) {
            return null;
        }

        return this.props.children;
    }
}

export function WorkflowConfig({
    workflow,
    actionId,
    rows,
    registry = WORKFLOWS,
}: WorkflowConfigSlotProps) {
    // The same resolver `ActionLayer` asks before it chooses a row shape. Held
    // in one place so the slot and its host cannot disagree about whether there
    // is a surface here — a disagreement draws a collapsed action whose body is
    // empty and whose controls are hidden inside it.
    const surface = configSurfaceFor(workflow, rows, registry);

    if (surface === null) {
        return null;
    }

    const Config = surface.Surface;

    return (
        <WorkflowConfigBoundary key={workflow}>
            <Config actionId={actionId} rows={surface.rows} />
        </WorkflowConfigBoundary>
    );
}
