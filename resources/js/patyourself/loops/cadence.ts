import type { ActiveActionData } from '@/patyourself/types';

/** The scheduling fields any cadence description is built from. */
type CadenceSource = Pick<
    ActiveActionData,
    'schedule_kind' | 'anchor' | 'recurrence' | 'next_occurrence_at'
> & {
    /**
     * The series anchor as an instant, ISO 8601. Optional because
     * `currentCadenceLabel`'s caller reads `ActiveActionData`, which does not
     * carry one — absent there, this behaves exactly as it did before.
     */
    starts_at?: string | null;
};

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

    // A series that has not begun has materialised no occasions, so
    // `next_occurrence_at` is null and the lines below would read a bare
    // "weekly" — naming the cadence while saying nothing about the date that
    // was chosen for it. The anchor is what such an action is waiting on, so
    // it is what the line names.
    //
    // Compared as instants rather than as date strings, so the answer does not
    // depend on the browser's zone. A *past* anchor means the series is
    // running, and then the next occasion is the right thing to name — or
    // nothing, for a grid already exhausted for today.
    const startsAt = action.starts_at ?? null;

    if (startsAt !== null && new Date(startsAt) > new Date()) {
        const start = `from ${formatDate(startsAt)} at ${formatTime(startsAt)}`;

        return action.recurrence === null ? start : `${action.recurrence} ${start}`;
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

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}
