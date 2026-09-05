/**
 * Which workflow a loop uses, and what that routes to on screen.
 *
 * A workflow is how the app records *what happened* during an occasion, on top
 * of whether it happened at all. The loop, its experiment, its schedule and its
 * verdict are unchanged by one — a workflow brings a recording surface and
 * nothing else.
 *
 * Empty until the first module ships. A loop with no workflow — every loop
 * today, and the ordinary case forever — routes to nothing here and keeps the
 * plain screen it has always had.
 *
 * The registry is mirrored on the server in `config/workflows.php`, which is
 * the one that decides what a name may be set to. This side decides only what
 * it draws.
 */
import type { ComponentType } from 'react';

/** What a recording surface is told about the occasion it is recording. */
export interface WorkflowRecordProps {
    /**
     * The occasion, when one has been materialised. Null for an anchored action
     * whose slot does not exist yet — the same case the plain screen already
     * handles by posting to the action route instead.
     */
    occurrenceId: number | null;
    actionId: number;
}

export interface WorkflowSpec {
    name: string;
    label: string;
    /**
     * What this workflow draws to record an occasion, or null when it draws
     * nothing. Null is an empty attachment site, not a missing surface.
     */
    record: ComponentType<WorkflowRecordProps> | null;
}

export type WorkflowRegistry = Record<string, WorkflowSpec>;

/** Every workflow this app draws, keyed by the name stored on the loop. */
export const WORKFLOWS: WorkflowRegistry = {};

/**
 * The workflow a loop names, or null for "no workflow" — which is what both a
 * null name and an unrecognised one mean, and which draws the plain screen.
 *
 * Naming a workflow the registry does not know must never be able to break the
 * screen — the same rule scenes, room objects and animations already follow.
 *
 * `Object.hasOwn` rather than `registry[name] ?? null`: a plain object's lookup
 * walks the prototype chain, so names like `'constructor'` or `'toString'`
 * resolve to an inherited `Object` value that is truthy and therefore never
 * triggers the fallback — the lookup then fails further down where the caller
 * reads `.record` off what it thinks is a `WorkflowSpec`. `scenes.ts` records
 * the same trap; this is the second registry to hold the rule.
 *
 * The registry is a parameter so a test can route against its own entries
 * without one being shipped for it. The default is the real one, and the
 * fallback is asserted against that default too.
 */
export function workflowFor(
    name: string | null | undefined,
    registry: WorkflowRegistry = WORKFLOWS,
): WorkflowSpec | null {
    if (name === null || name === undefined) {
        return null;
    }

    return Object.hasOwn(registry, name) ? registry[name] : null;
}
