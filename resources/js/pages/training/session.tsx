import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import VerdictForm from '@/patyourself/verdict-form';
import { store as storeLog } from '@/routes/occurrences/logs';
import { show as showExercise } from '@/routes/training/exercise';

export interface SessionExerciseData {
    id: number;
    name: string;
    target_sets: number;
    target_reps: number;
    /** How many sets are already recorded against this occasion — never more
     *  than `target_sets`, since a set beyond the target still records. */
    performed_count: number;
}

export interface SessionProps {
    occurrence_id: number;
    action_id: number;
    action_title: string;
    /** ISO timestamp, already in the user's own timezone (see
     *  `SessionController::show`). */
    scheduled_for: string;
    /** The action's routine, in position order. Empty for a plain action —
     *  see `SessionScreen` for why that is the additive case, not an error. */
    exercises: SessionExerciseData[];
}

/**
 * The dedicated session screen: one occasion's routine, its target sets/reps,
 * and how many of each are already recorded, plus the plain verdict controls
 * every occasion already has.
 *
 * The tracker is additive. An action with no routine (`exercises` empty)
 * renders no tracker at all and goes straight to the verdict controls — the
 * same screen a plain, non-gym occasion would show if this workflow ever drew
 * one. Entering sets never decides the outcome: Done/Missed is still pressed
 * separately, by a person, and a failure still asks for the reason in their
 * own words, exactly as it does everywhere else in the app.
 *
 * Record, never prescribe: target sets and reps are shown because the user
 * wrote them on the routine, not because this screen is telling them what to
 * lift. Nothing here suggests a weight, names a record, or shows a
 * fraction — see `SessionScreenTest` and this file's own tests for the
 * line drawn around that.
 */
export default function Session({
    occurrence_id: occurrenceId,
    action_title: actionTitle,
    scheduled_for: scheduledFor,
    exercises,
}: SessionProps) {
    const back = (
        <Link
            href="/dashboard"
            className="-ml-1 flex size-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
            aria-label="Back to today"
        >
            <ChevronLeft className="size-5" />
        </Link>
    );

    return (
        <CoachLayout title={actionTitle} headerLeading={back}>
            <div className="flex flex-col gap-6">
                <p className="text-sm text-foreground">
                    {actionTitle}
                    <span className="text-muted-foreground">
                        {' · '}
                        {formatSessionDay(scheduledFor)}
                    </span>
                </p>

                {exercises.length > 0 && (
                    <ul
                        data-testid="exercise-tracker"
                        className="flex flex-col divide-y divide-border"
                    >
                        {exercises.map((exercise) => (
                            <ExerciseRow
                                key={exercise.id}
                                occurrenceId={occurrenceId}
                                exercise={exercise}
                            />
                        ))}
                    </ul>
                )}

                <VerdictForm
                    action={storeLog.url(occurrenceId)}
                    className="flex flex-col gap-2"
                    testId="session-verdict-form"
                />
            </div>
        </CoachLayout>
    );
}

function ExerciseRow({
    occurrenceId,
    exercise,
}: {
    occurrenceId: number;
    exercise: SessionExerciseData;
}) {
    return (
        <li
            data-testid={`exercise-row-${exercise.id}`}
            className="py-3"
        >
            <Link
                href={showExercise.url({
                    occurrence: occurrenceId,
                    exercise: exercise.id,
                })}
                className="flex items-center justify-between gap-3"
            >
                <span
                    data-testid="exercise-name"
                    className="min-w-0 flex-1 truncate text-sm text-foreground"
                >
                    {exercise.name}
                </span>
                <span
                    data-testid={`exercise-target-${exercise.id}`}
                    className="shrink-0 font-mono text-xs text-muted-foreground"
                >
                    {exercise.target_sets} x {exercise.target_reps}
                </span>
                <SetDots
                    target={exercise.target_sets}
                    performed={exercise.performed_count}
                    testId={`exercise-dots-${exercise.id}`}
                />
            </Link>
        </li>
    );
}

/**
 * One dot per target set, filled to the number actually recorded — never a
 * count, never a fraction. `performed` can exceed `target` (a set beyond the
 * target still records); every dot still reads filled in that case, since
 * there is nothing left to leave open.
 */
function SetDots({
    target,
    performed,
    testId,
}: {
    target: number;
    performed: number;
    testId: string;
}) {
    return (
        <span
            data-testid={testId}
            className="flex shrink-0 gap-1"
            aria-label={`${Math.min(performed, target)} of ${target} sets recorded`}
        >
            {Array.from({ length: target }, (_, index) => index < performed).map(
                (filled, index) => (
                    <span
                        key={index}
                        data-testid="set-dot"
                        data-filled={filled}
                        aria-hidden="true"
                        className={cn(
                            'size-2 rounded-full',
                            filled ? 'bg-foreground' : 'bg-border',
                        )}
                    />
                ),
            )}
        </span>
    );
}

/**
 * "Wednesday 10 September" — the header names the day, in the offset it was
 * sent in (see `SessionController::show`), not the browser's own timezone. A
 * fixed 'en-GB' locale keeps the day-then-month order deterministic across
 * environments, the same reason `dashboard.tsx`'s own `formatDay` pins one.
 */
function formatSessionDay(iso: string): string {
    const [datePart] = iso.split('T');
    const [year, month, day] = datePart.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        'en-GB',
        { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' },
    );
}
