import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import CoachLayout from '@/layouts/coach-layout';
import { formatOccasionDay } from '@/patyourself/occasion-date';
import SetGrid from '@/patyourself/training/set-grid';
import type { SetGridPerformedSet } from '@/patyourself/training/set-grid';
import { show as showProgression } from '@/routes/training/progression';
import { show as showSession } from '@/routes/training/session';

export interface ExerciseData {
    id: number;
    name: string;
    /** Null when this exercise was opened outside any routine row — the
     *  screen still renders, just with nothing to target and no pending row
     *  to fill (see `SetGrid`'s own fallback). */
    target_sets: number | null;
    target_reps: number | null;
    /** The catalogue's own step-by-step instructions. Empty, never absent —
     *  a user-added exercise or one still missing a description imports as
     *  `[]`, not null. */
    instructions: string[];
    /** Always null in v1 — the catalogue ships no images at all. */
    image_path: string | null;
}

export interface LastPerformanceData {
    /** ISO timestamp, already in the user's own timezone. */
    performed_at: string;
    sets: SetGridPerformedSet[];
}

export interface ExerciseProps {
    occurrence_id: number;
    exercise: ExerciseData;
    /** Already recorded against this occasion, in `set_number` order. */
    performed_sets: SetGridPerformedSet[];
    /** Null when there is no prior session for this exercise — absent from
     *  the screen entirely, not an empty or zeroed row. */
    last: LastPerformanceData | null;
}

/**
 * The exercise screen: what to lift, what was lifted last time, and where
 * sets against this occasion get recorded.
 *
 * Record, never prescribe. The only prescriptive numbers here are the
 * routine's own target sets/reps — written by the user onto the routine,
 * not suggested by this screen — and the sets already recorded this
 * occasion. `last` is pure information: last session's own numbers, on
 * screen so nobody has to remember them, never a suggested weight, a record
 * detection, a 1RM, a fraction of one, or a trend called progress. See this
 * file's own tests, and `SetGrid`'s, for the line drawn around that.
 */
export default function ExerciseScreen({
    occurrence_id: occurrenceId,
    exercise,
    performed_sets: performedSets,
    last,
}: ExerciseProps) {
    const back = (
        <Link
            href={showSession.url(occurrenceId)}
            className="-ml-1 flex size-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
            aria-label="Back to session"
        >
            <ChevronLeft className="size-5" />
        </Link>
    );

    const hasTarget =
        exercise.target_sets !== null && exercise.target_reps !== null;

    return (
        <CoachLayout title={exercise.name} headerLeading={back}>
            <div className="flex flex-col gap-6">
                <div className="flex items-baseline justify-between gap-3">
                    <p className="text-sm text-foreground">{exercise.name}</p>
                    {hasTarget && (
                        <span
                            data-testid="exercise-target"
                            className="shrink-0 font-mono text-xs text-muted-foreground"
                        >
                            target {exercise.target_sets} x{' '}
                            {exercise.target_reps}
                        </span>
                    )}
                </div>

                {last && (
                    <p
                        data-testid="last-performance"
                        className="text-right text-xs text-muted-foreground"
                    >
                        last {formatLastPerformance(last)}
                    </p>
                )}

                <Link
                    href={showProgression.url(exercise.id)}
                    className="text-right text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                >
                    Earlier sessions
                </Link>

                {exercise.image_path && (
                    <img
                        src={exercise.image_path}
                        alt={exercise.name}
                        className="w-full rounded-md"
                    />
                )}

                {/* With no routine row there is no target to work down to, so the screen
                    offers one open row past whatever is already recorded — a set at a
                    time, for as long as the user keeps going. Without the `+ 1` the grid
                    computes zero pending rows and a recording screen offers nowhere to
                    record, which is reachable by taking an exercise off the routine
                    mid-session. It is not a target: nothing here says how many sets there
                    should be. */}
                <SetGrid
                    occurrenceId={occurrenceId}
                    exerciseId={exercise.id}
                    targetSets={exercise.target_sets ?? performedSets.length + 1}
                    performedSets={performedSets}
                />

                {exercise.instructions.length > 0 && (
                    <ol
                        data-testid="exercise-instructions"
                        className="flex flex-col gap-1 text-sm text-muted-foreground"
                    >
                        {exercise.instructions.map((step, index) => (
                            <li key={index}>{step}</li>
                        ))}
                    </ol>
                )}
            </div>
        </CoachLayout>
    );
}

/**
 * "60kg · 10 / 10 / 8 · 2 Sep" — the weight is read off the first set only
 * (v1 does not attempt to summarise a session that mixed weights), reps are
 * every set in order, and the date drops the weekday the way a compact
 * summary line should. Blank, not "0kg", when that first set has no weight
 * recorded — see `SetGrid`'s own note on why null and zero cannot render the
 * same way.
 */
function formatLastPerformance(last: LastPerformanceData): string {
    const weight = last.sets[0]?.weight ?? null;
    const weightText = weight === null ? '' : `${weight}kg`;
    const repsText = last.sets.map((set) => set.reps).join(' / ');
    const dateText = formatOccasionDay(last.performed_at, {
        day: 'numeric',
        month: 'short',
    });

    return [weightText, repsText, dateText].filter(Boolean).join(' · ');
}
