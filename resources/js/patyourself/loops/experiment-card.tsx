import { ExperimentHeader } from '@/patyourself/experiment-header';
import { ConcludeExperimentForm } from '@/patyourself/loops/conclude-experiment-form';
import type { CurrentVersionData, StrategyData } from '@/patyourself/types';

interface ExperimentCardProps {
    /** The active experiment's own record. Null between experiments. */
    current: CurrentVersionData | null;
    /**
     * The version currently running, whatever its verdict. A `worked` verdict
     * does not supersede — ConcludeExperiment is explicit that such a version
     * keeps running — so this is the version the card is about even after it
     * has been concluded. Undefined only between experiments, when nothing is
     * running.
     */
    runningExperiment: StrategyData | undefined;
    interventionPoint: string | null;
    previousRate: number | null;
}

/**
 * What is being tested, on one card, at the top of the loop screen.
 *
 * This exists because the experiment used to arrive in two disconnected
 * halves: `ExperimentHeader` gave the version, the intervention point and the
 * run state at the top of the screen, while the hypothesis — the part that
 * says what is actually being tried — rendered a screen and a half below,
 * inside the timeline, among superseded versions. Neither half read as the
 * subject of the page.
 *
 * It **wraps** `ExperimentHeader` rather than absorbing it. The header holds
 * `runState()` and the evidence line, it is covered by its own tests, and
 * copying either into here would give the app two places that word a run
 * state and two that decide when to show a delta.
 *
 * The verdict lives here only while the question is live. See below.
 */
export function ExperimentCard({
    current,
    runningExperiment,
    interventionPoint,
    previousRate,
}: ExperimentCardProps) {
    // The verdict is the end of an experiment's life, not its daily business.
    // `is_under_review` is the app's existing answer to "is that question live
    // yet" — Strategy::isUnderReview() on the server, and the same flag
    // ExperimentHeader already reads to word the run state "Ready for a
    // verdict". When it is not live the form is not gone, it is in loop
    // settings as "End this experiment early"; rare is not forbidden.
    //
    // Safe now that `runningExperiment` admits a concluded version too:
    // `Strategy::isUnderReview()` is defined as active AND not concluded AND
    // past its review date, so a version that already carries a verdict
    // reports `false` by construction. No extra check is needed here.
    const readyForVerdict = runningExperiment?.is_under_review === true;

    return (
        <section
            data-testid="experiment-card"
            className="flex flex-col gap-3 rounded-xl border border-border p-4"
        >
            <ExperimentHeader
                current={current}
                interventionPoint={interventionPoint}
                previousRate={previousRate}
            />

            {runningExperiment && (
                <p className="text-sm text-foreground">
                    {runningExperiment.approach}
                </p>
            )}

            {readyForVerdict && runningExperiment && (
                <ConcludeExperimentForm
                    strategyId={runningExperiment.id}
                    isUnderReview
                />
            )}
        </section>
    );
}
