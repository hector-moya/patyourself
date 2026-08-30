import type { ActiveActionData } from '@/patyourself/types';

/** The scheduling fields any cadence description is built from. */
type CadenceSource = Pick<
    ActiveActionData,
    'schedule_kind' | 'anchor' | 'recurrence' | 'next_occurrence_at'
>;

/**
 * A human-readable description of an action's cadence — "daily at 19:00",
 * "after brushing teeth".
 *
 * Combines recurrence and time only when both are present, returns whichever
 * single value exists otherwise, and returns null rather than a partial
 * string — never "daily at " with nothing after it. `next_occurrence_at` is
 * null whenever the occurrence grid has nothing left to report (a
 * cue-anchored action has no grid, and a clock action's grid only extends to
 * the end of the local day), and that is a legitimate state, not a gap to
 * paper over.
 */
export function cadenceLabel(action: CadenceSource): string | null {
    if (action.schedule_kind === 'anchored') {
        return action.anchor === null ? null : `after ${action.anchor}`;
    }

    const time =
        action.next_occurrence_at === null
            ? null
            : formatTime(action.next_occurrence_at);

    if (action.recurrence !== null && time !== null) {
        return `${action.recurrence} at ${time}`;
    }

    return action.recurrence ?? time;
}

/**
 * The active action's cadence, named for the start-experiment form's keep
 * option so inheriting it is a legible choice rather than a guess at what
 * "keep" means. Null when the loop has no active action.
 */
export function currentCadenceLabel(
    activeAction: ActiveActionData | null,
): string | null {
    return activeAction === null ? null : cadenceLabel(activeAction);
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}
