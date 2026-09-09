import CoachLayout from '@/layouts/coach-layout';
import { formatOccasionDay } from '@/patyourself/occasion-date';

export interface ProgressionSet {
    reps: number;
    /** Kilograms, or null for body weight — never zero. Zero is a weight;
     *  null is "not applicable", and the two must render differently. */
    weight: number | null;
}

export interface ProgressionSession {
    occurrence_id: number;
    /** ISO timestamp, already in the user's own timezone. */
    performed_at: string;
    sets: ProgressionSet[];
}

export interface ProgressionProps {
    exercise: { id: number; name: string };
    /** Newest first. Empty, never absent, when nothing has been recorded. */
    sessions: ProgressionSession[];
}

/**
 * What was lifted on one exercise, newest first.
 *
 * Record, never prescribe — and this is the screen where that is hardest to
 * hold, so it is stated hardest here. There is no chart: there is nothing to
 * chart until there is history, and a sparkline over three sessions is
 * decoration. There is no record detection, no estimated 1RM, no volume
 * total, no fraction of one, and no trend named. Every number on this screen
 * is one the user recorded, shown back to them unchanged.
 *
 * This screen carries no back control. `CoachLayout`'s `headerLeading` is
 * optional, and there are two ways in — the exercise screen mid-session, and
 * a routine row on the loop's own record — with nothing in the payload saying
 * which. A fixed back link would send half its visitors somewhere they did
 * not come from, and there is no `GET /exercises/{exercise}` route for one to
 * point at in any case.
 */
export default function ProgressionScreen({
    exercise,
    sessions,
}: ProgressionProps) {
    return (
        <CoachLayout title={exercise.name}>
            <div className="flex flex-col gap-6">
                <p className="text-sm text-foreground">{exercise.name}</p>

                {sessions.length === 0 ? (
                    <p
                        data-testid="progression-empty"
                        className="text-sm text-muted-foreground"
                    >
                        Nothing recorded on this one yet.
                    </p>
                ) : (
                    <ol
                        data-testid="progression-list"
                        className="flex flex-col divide-y divide-border"
                    >
                        {sessions.map((session) => (
                            <li
                                key={session.occurrence_id}
                                data-testid={`progression-row-${session.occurrence_id}`}
                                className="flex items-baseline justify-between gap-3 py-2"
                            >
                                <span className="min-w-0 font-mono text-sm text-foreground">
                                    {formatSession(session.sets)}
                                </span>
                                <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                    {formatOccasionDay(session.performed_at, {
                                        day: 'numeric',
                                        month: 'short',
                                    })}
                                </span>
                            </li>
                        ))}
                    </ol>
                )}
            </div>
        </CoachLayout>
    );
}

/**
 * "60kg · 10 / 10 / 8" when every set carried the same weight, and
 * "60kg × 10 / 60kg × 10 / 65kg × 8" when they did not.
 *
 * The exercise screen's own last-session line reads the weight off the first
 * set and says so, which is a fair simplification for one line about one
 * session. Across a history it would be a false statement: a session of
 * 60/60/65 is not a session at 60. So a mixed session is spelled out rather
 * than collapsed.
 *
 * A null weight is body weight — "not applicable" — and contributes bare reps
 * in either form. It is never rendered as `0kg`: zero is a weight, and a
 * reader cannot tell the two apart.
 */
export function formatSession(sets: ProgressionSet[]): string {
    if (sets.length === 0) {
        return '';
    }

    const distinctWeights = new Set(sets.map((set) => set.weight));

    if (distinctWeights.size === 1) {
        const weight = sets[0].weight;
        const reps = sets.map((set) => set.reps).join(' / ');

        return weight === null ? reps : `${weight}kg · ${reps}`;
    }

    return sets
        .map((set) =>
            set.weight === null
                ? `${set.reps}`
                : `${set.weight}kg × ${set.reps}`,
        )
        .join(' / ');
}
