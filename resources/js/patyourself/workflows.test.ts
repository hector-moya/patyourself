import { describe, expect, it } from 'vitest';

import type { WorkflowConfigRow, WorkflowRegistry } from './workflows';
import { WORKFLOWS, configSurfaceFor, workflowFor } from './workflows';

function Surface() {
    return null;
}

const FAKE: WorkflowRegistry = {
    'spec-fake': {
        name: 'spec-fake',
        label: 'Spec fake',
        config: null,
        record: Surface,
    },
    bare: { name: 'bare', label: 'Bare', config: null, record: null },
    configures: {
        name: 'configures',
        label: 'Configures',
        config: Surface,
        record: null,
    },
};

describe('workflowFor', () => {
    it('routes a registered name to its recording surface', () => {
        expect(workflowFor('spec-fake', FAKE)?.record).toBe(Surface);
    });

    it('routes a registered name that records nothing to an entry with no surface', () => {
        const spec = workflowFor('bare', FAKE);

        expect(spec).not.toBeNull();
        expect(spec?.record).toBeNull();
    });

    it('routes null to no workflow', () => {
        expect(workflowFor(null, FAKE)).toBeNull();
    });

    it('routes undefined to no workflow', () => {
        expect(workflowFor(undefined, FAKE)).toBeNull();
    });

    it('routes an unknown name to no workflow', () => {
        expect(workflowFor('gimnasio', FAKE)).toBeNull();
    });

    // A bare lookup walks the prototype chain, so these resolve to an
    // inherited Object value that is truthy and never triggers a `??`
    // fallback — the caller then reads .record off it. Found once already in
    // scenes.ts; this is the same trap in a second registry.
    it.each(['constructor', 'toString', 'hasOwnProperty', '__proto__'])(
        'routes the inherited property %s to no workflow',
        (name) => {
            expect(workflowFor(name, FAKE)).toBeNull();
        },
    );

    it('applies the same fallback to the shipped registry by default', () => {
        expect(workflowFor('constructor')).toBeNull();
        expect(workflowFor('toString')).toBeNull();
        expect(workflowFor('gimnasio')).toBeNull();
        expect(workflowFor(null)).toBeNull();
    });

    it('ships gym as its first, and so far only, workflow', () => {
        // Pinned so the default-registry assertions above keep meaning what
        // they say: an unrelated key quietly added here would not be caught
        // by any of them, since none queries this exact set.
        expect(Object.keys(WORKFLOWS)).toEqual(['gym']);
    });
});

describe('configSurfaceFor', () => {
    const ROWS: WorkflowConfigRow[] = [
        {
            id: 1,
            exercise_id: 2,
            exercise_name: 'Barbell Row',
            position: 1,
            target_sets: 3,
            target_reps: 10,
        },
    ];

    it('resolves a registered config surface and the rows it draws', () => {
        const surface = configSurfaceFor('configures', ROWS, FAKE);

        expect(surface?.Surface).toBe(Surface);
        expect(surface?.rows).toBe(ROWS);
    });

    it('resolves nothing when the loop names no workflow', () => {
        expect(configSurfaceFor(null, ROWS, FAKE)).toBeNull();
        expect(configSurfaceFor(undefined, ROWS, FAKE)).toBeNull();
    });

    it('resolves nothing for a name the registry does not know', () => {
        expect(configSurfaceFor('gimnasio', ROWS, FAKE)).toBeNull();
    });

    // The same prototype-chain trap workflowFor guards, reached through the
    // resolver instead. A bare lookup would return a truthy inherited value
    // and the caller would read .config off it.
    it('resolves nothing for an inherited property name', () => {
        expect(configSurfaceFor('constructor', ROWS, FAKE)).toBeNull();
        expect(configSurfaceFor('toString', ROWS, FAKE)).toBeNull();
    });

    it('resolves nothing for a workflow whose config site is empty', () => {
        expect(configSurfaceFor('spec-fake', ROWS, FAKE)).toBeNull();
    });

    it('resolves nothing when the loop does not configure actions', () => {
        expect(configSurfaceFor('configures', null, FAKE)).toBeNull();
    });

    // rows: [] is "this action's routine is empty", which still has a
    // surface to draw. Only rows: null means there is no surface at all.
    it('resolves a surface for an empty routine', () => {
        const surface = configSurfaceFor('configures', [], FAKE);

        expect(surface).not.toBeNull();
        expect(surface?.rows).toEqual([]);
    });

    it('resolves gym against the shipped registry by default', () => {
        expect(configSurfaceFor('gym', [])).not.toBeNull();
        expect(configSurfaceFor('gym', null)).toBeNull();
        expect(configSurfaceFor(null, [])).toBeNull();
    });
});
