import { describe, expect, it } from 'vitest';

import type { WorkflowRegistry } from './workflows';
import { WORKFLOWS, workflowFor } from './workflows';

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
