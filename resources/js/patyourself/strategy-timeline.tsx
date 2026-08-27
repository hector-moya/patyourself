import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';
import type { ExperimentData, StrategyData } from '@/patyourself/types';

const CHANGE_REASON: Record<string, string> = {
    initial: 'Starting point',
    stacked_on_success: 'Stacked on success',
    restrategized_on_failure: 'Restrategized after a setback',
};

/**
 * Verdicts read as judgements of the strategy, never of the person running it.
 * "did not hold" is about the intervention; "you failed" would not be.
 */
const VERDICT: Record<string, string> = {
    worked: 'Worked',
    failed: 'Did not hold',
    inconclusive: 'Inconclusive',
};

/**
 * How far into its run an experiment is. A null `planned_days` means
 * open-ended, which is a legitimate state and must never render as a countdown
 * or as a zero-day experiment.
 */
function runLength(strategy: StrategyData): string {
    return strategy.planned_days === null
        ? `Day ${strategy.day_of_experiment} · open-ended`
        : `Day ${strategy.day_of_experiment} of ${strategy.planned_days}`;
}

/**
 * The evidence under a version. Zero is meaningful — it is the difference
 * between a strategy that failed and one that was never tested — so it is
 * spelled out rather than left as a blank.
 */
function evidence(count: number): string {
    return count === 0
        ? 'Not yet tested'
        : `${count} outcome${count === 1 ? '' : 's'} recorded`;
}

/**
 * The experiment ladder: every version, oldest → newest, with the evidence
 * recorded under each. Read-only — history is only ever appended to.
 *
 * When `experiments` is supplied the per-version totals replace the plain
 * outcome count, which is what turns a list of things tried into a comparison
 * between them. Logs attribute through `actions.strategy_id`, so a v1 failure
 * stays on v1 even while v2 is the active version.
 */
export function StrategyTimeline({
    strategies,
    experiments,
}: {
    strategies: StrategyData[];
    experiments?: ExperimentData[];
}) {
    return (
        <section>
            <SectionHeading>
                Experiments
                <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                    ({strategies.length})
                </span>
            </SectionHeading>

            {strategies.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No strategy yet.
                </p>
            ) : (
                <ol className="flex flex-col">
                    {strategies.map((strategy, index) => (
                        <TimelineNode
                            key={strategy.id}
                            strategy={strategy}
                            experiment={experiments?.find(
                                (candidate) =>
                                    candidate.version === strategy.version,
                            )}
                            last={index === strategies.length - 1}
                        />
                    ))}
                </ol>
            )}
        </section>
    );
}

function TimelineNode({
    strategy,
    experiment,
    last,
}: {
    strategy: StrategyData;
    experiment?: ExperimentData;
    last: boolean;
}) {
    const active = strategy.status === 'active';

    return (
        <li className="flex gap-3">
            <div className="flex flex-col items-center">
                <span
                    className={cn(
                        'mt-1 size-3 shrink-0 rounded-full border-2',
                        active
                            ? 'border-primary bg-primary'
                            : 'border-border bg-background',
                    )}
                />
                {!last && <span className="my-1 w-px flex-1 bg-border" />}
            </div>

            <div className="flex-1 pb-4">
                <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted-foreground">
                        v{strategy.version} · {strategy.intervention_point}
                    </span>
                    {active && (
                        <span className="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                            active
                        </span>
                    )}
                    {strategy.is_under_review && (
                        <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                            ready to conclude
                        </span>
                    )}
                </div>

                <p className="mt-1 text-sm text-foreground">
                    {strategy.approach}
                </p>

                <p className="mt-1 text-xs text-muted-foreground">
                    {active && !strategy.verdict && (
                        <span>{runLength(strategy)}</span>
                    )}
                    {strategy.verdict && (
                        <span>
                            {VERDICT[strategy.verdict] ?? strategy.verdict}
                        </span>
                    )}
                    {experiment === undefined &&
                        strategy.outcomes_recorded !== undefined && (
                            <span>
                                {(active && !strategy.verdict) ||
                                strategy.verdict
                                    ? ' · '
                                    : ''}
                                {evidence(strategy.outcomes_recorded)}
                            </span>
                        )}
                </p>

                {experiment && (
                    <Evidence experiment={experiment} />
                )}

                {strategy.verdict_note && (
                    <p className="mt-1 text-xs text-muted-foreground/80 italic">
                        “{strategy.verdict_note}”
                    </p>
                )}

                {strategy.change_reason && (
                    <p className="mt-1 text-xs text-muted-foreground">
                        {CHANGE_REASON[strategy.change_reason] ??
                            strategy.change_reason}
                    </p>
                )}

                {strategy.superseded_reason && (
                    <p className="mt-1 text-xs text-muted-foreground/80 italic">
                        “{strategy.superseded_reason}”
                    </p>
                )}
            </div>
        </li>
    );
}

/**
 * What this version actually produced.
 *
 * Raw counts lead and the rate follows: with a handful of logs a percentage
 * hides its own denominator. `skipped` is excluded — the occasion never
 * happened, so it belongs in neither half of the fraction.
 *
 * Zero outcomes reads "Not yet tested" rather than 0%, because a strategy that
 * was never run and one that never held are different findings.
 */
function Evidence({ experiment }: { experiment: ExperimentData }) {
    const { completed, failed } = experiment.totals;
    const decided = completed + failed;

    const reasons = experiment.outcomes.filter(
        (outcome) => outcome.outcome === 'failed' && outcome.reason !== null,
    );

    return (
        <>
            <p
                data-testid={`experiment-evidence-${experiment.version}`}
                className="mt-1 font-mono text-xs text-muted-foreground"
            >
                {decided === 0
                    ? 'Not yet tested'
                    : `${completed} of ${decided} held · ${Math.round((completed / decided) * 100)}%`}
            </p>

            {reasons.map((outcome, index) => (
                <p
                    key={`${experiment.version}-${index}`}
                    data-testid={`experiment-reason-${experiment.version}-${index}`}
                    className="mt-1 text-xs whitespace-pre-line text-muted-foreground/80 italic"
                >
                    {outcome.reason}
                </p>
            ))}
        </>
    );
}

export function SectionHeading({ children }: { children: ReactNode }) {
    return (
        <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </h2>
    );
}
