import type { CurrentVersionData } from '@/patyourself/types';

interface ExperimentHeaderProps {
    /** The active experiment's own record. Null between experiments. */
    current: CurrentVersionData | null;
    /** Where the active version intervenes in the chain. */
    interventionPoint: string | null;
    /** The previous concluded version's rate, for the comparison. Null when
     *  this is the first experiment — the delta is then omitted entirely. */
    previousRate: number | null;
}

/**
 * How far into the current experiment the loop is, and whether it is holding.
 *
 * The first thing on the lab record, because "what am I testing and how is it
 * going" should not require scrolling. Fed by LoopProgress::forCurrentVersion(),
 * which reports the active version's own evidence rather than the loop's
 * lifetime — a fresh intervention should not inherit the previous version's
 * record and read as though it had earned it.
 *
 * Three rules hold this component together:
 *
 * - `planned_days: null` is open-ended. It never renders as a countdown.
 * - A falling delta reads in exactly the same weight as a rising one. It is
 *   information about the strategy, never a warning about the person.
 * - The streak shows while it runs and disappears when it breaks. No reset, no
 *   zero, no milestones. The notebook does not count a loss back at its owner.
 */
export function ExperimentHeader({
    current,
    interventionPoint,
    previousRate,
}: ExperimentHeaderProps) {
    if (current === null) {
        return (
            <section className="flex flex-col gap-1">
                <p
                    data-testid="experiment-state"
                    className="text-sm text-muted-foreground"
                >
                    No experiment running · logging continues
                </p>
            </section>
        );
    }

    const decided = current.totals.completed + current.totals.failed;
    const rate = current.completion_rate;
    const streaking =
        current.streak.outcome === 'completed' && current.streak.length > 0;
    const showDelta = previousRate !== null && rate !== null;

    return (
        <section className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span className="font-mono text-xs font-semibold tracking-wide text-foreground">
                    v{current.version}
                </span>
                {interventionPoint && (
                    <span className="text-xs text-muted-foreground">
                        · {interventionPoint}
                    </span>
                )}
                <span
                    data-testid="experiment-state"
                    className="ml-auto font-mono text-xs font-semibold tracking-wide text-foreground uppercase"
                >
                    {runState(current)}
                </span>
            </div>

            {decided > 0 && (
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span className="font-mono text-sm text-foreground">
                        {current.totals.completed} of {decided} held
                    </span>
                    {rate !== null && (
                        <span className="font-mono text-xs text-muted-foreground">
                            {rate}%
                        </span>
                    )}
                    {showDelta && (
                        <span
                            data-testid="experiment-delta"
                            className="font-mono text-xs text-muted-foreground"
                        >
                            {rate >= previousRate ? '▲' : '▼'}{' '}
                            {rate >= previousRate ? 'up from' : 'down from'}{' '}
                            {previousRate}%
                        </span>
                    )}
                    {streaking && (
                        <span className="font-mono text-xs text-muted-foreground">
                            {current.streak.length} in a row
                        </span>
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * Past its review date the day count stops being the useful fact — a version on
 * day 15 of a 14-day run should ask for a verdict rather than report an overrun.
 * So `is_under_review` is tested before the count, not after it.
 */
function runState(current: CurrentVersionData): string {
    if (current.is_under_review) {
        return 'Ready for a verdict';
    }

    if (current.planned_days === null) {
        return `Day ${current.day_of_experiment} · open-ended`;
    }

    return `Day ${current.day_of_experiment} of ${current.planned_days}`;
}
