import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import type { IntentionData, StrategyData } from '@/patyourself/types';

interface LoopShowProps {
    intention: IntentionData;
    strategies: StrategyData[];
}

/**
 * Loop detail — the habit anatomy (cue → craving → response → reward, with the
 * stage the active strategy intervenes on highlighted) and the versioned
 * strategy history as a timeline. Read-only: history is only ever appended to.
 */
export default function LoopShow({ intention, strategies }: LoopShowProps) {
    const back = (
        <Link
            href="/intentions"
            className="-ml-1 flex size-8 items-center justify-center rounded-md text-muted-foreground hover:text-foreground"
            aria-label="Back to loops"
        >
            <ChevronLeft className="size-5" />
        </Link>
    );

    return (
        <CoachLayout
            title={intention.title}
            headerLeading={back}
            bottomNav={<BottomNav />}
        >
            <div className="flex flex-col gap-6">
                <section className="flex items-center gap-2">
                    <Badge>
                        {intention.type === 'build' ? 'Build' : 'Break'}
                    </Badge>
                    <Badge>{intention.status}</Badge>
                </section>

                {intention.description && (
                    <p className="text-sm text-muted-foreground">
                        {intention.description}
                    </p>
                )}

                <Anatomy
                    intention={intention}
                    interventionPoint={
                        intention.strategy?.intervention_point ?? null
                    }
                />

                <StrategyTimeline strategies={strategies} />
            </div>
        </CoachLayout>
    );
}

const STAGES = [
    { key: 'cue', label: 'Cue', hint: 'the trigger' },
    { key: 'craving', label: 'Craving', hint: 'the motivation' },
    { key: 'response', label: 'Response', hint: 'the behaviour' },
    { key: 'reward', label: 'Reward', hint: 'the payoff' },
] as const;

function Anatomy({
    intention,
    interventionPoint,
}: {
    intention: IntentionData;
    interventionPoint: string | null;
}) {
    return (
        <section>
            <SectionHeading>Habit anatomy</SectionHeading>
            <ol className="relative flex flex-col gap-2">
                {STAGES.map((stage, index) => {
                    const acts = stage.key === interventionPoint;

                    return (
                        <li key={stage.key} className="flex gap-3">
                            <div className="flex flex-col items-center">
                                <span
                                    className={cn(
                                        'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold',
                                        acts
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-muted text-muted-foreground',
                                    )}
                                >
                                    {index + 1}
                                </span>
                                {index < STAGES.length - 1 && (
                                    <span className="my-1 w-px flex-1 bg-border" />
                                )}
                            </div>

                            <div
                                className={cn(
                                    'mb-1 flex-1 rounded-xl border p-3',
                                    acts
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-border',
                                )}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        {stage.label}
                                        <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                                            · {stage.hint}
                                        </span>
                                    </span>
                                    {acts && (
                                        <span className="shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-medium text-primary">
                                            strategy acts here
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 text-sm text-foreground">
                                    {intention[stage.key]}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}

const CHANGE_REASON: Record<string, string> = {
    initial: 'Starting point',
    stacked_on_success: 'Stacked on success',
    restrategized_on_failure: 'Restrategized after a setback',
};

function StrategyTimeline({ strategies }: { strategies: StrategyData[] }) {
    return (
        <section>
            <SectionHeading>
                Strategy timeline
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
    last,
}: {
    strategy: StrategyData;
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
                </div>

                <p className="mt-1 text-sm text-foreground">
                    {strategy.approach}
                </p>

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

function SectionHeading({ children }: { children: React.ReactNode }) {
    return (
        <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
            {children}
        </h2>
    );
}

function Badge({ children }: { children: string }) {
    return (
        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
            {children}
        </span>
    );
}
