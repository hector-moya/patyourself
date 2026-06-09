import { Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import type { IntentionData, StrategyData } from '@/patyourself/types';

interface LoopShowProps {
    intention: IntentionData;
    strategies: StrategyData[];
}

/**
 * Loop detail — the habit anatomy (cue → craving → response → reward) and a
 * summary of the strategy history. The full anatomy visuals and interactive
 * strategy timeline land in Task 20; this routed scaffold renders the loop's
 * core data and keeps Loops navigation active.
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
                    <Badge>{intention.type}</Badge>
                    <Badge>{intention.status}</Badge>
                </section>

                {intention.description && (
                    <p className="text-sm text-muted-foreground">
                        {intention.description}
                    </p>
                )}

                <section>
                    <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        The loop
                    </h2>
                    <dl className="overflow-hidden rounded-xl border border-border">
                        <ChainRow label="Cue" value={intention.cue} />
                        <ChainRow label="Craving" value={intention.craving} />
                        <ChainRow label="Response" value={intention.response} />
                        <ChainRow
                            label="Reward"
                            value={intention.reward}
                            last
                        />
                    </dl>
                </section>

                <section>
                    <h2 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        Strategy history
                        <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                            ({strategies.length})
                        </span>
                    </h2>
                    {strategies.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No strategy yet.
                        </p>
                    ) : (
                        <ol className="flex flex-col gap-2">
                            {strategies.map((strategy) => (
                                <StrategyRow
                                    key={strategy.id}
                                    strategy={strategy}
                                />
                            ))}
                        </ol>
                    )}
                </section>
            </div>
        </CoachLayout>
    );
}

function ChainRow({
    label,
    value,
    last = false,
}: {
    label: string;
    value: string;
    last?: boolean;
}) {
    return (
        <div
            className={
                last
                    ? 'flex gap-3 px-4 py-3'
                    : 'flex gap-3 border-b border-border px-4 py-3'
            }
        >
            <dt className="w-20 shrink-0 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="text-sm text-foreground">{value}</dd>
        </div>
    );
}

function StrategyRow({ strategy }: { strategy: StrategyData }) {
    const active = strategy.status === 'active';

    return (
        <li
            className={`rounded-lg border p-3 ${
                active ? 'border-primary/40 bg-primary/5' : 'border-border'
            }`}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold text-muted-foreground">
                    v{strategy.version} · {strategy.intervention_point}
                </span>
                <span className="text-xs text-muted-foreground/80 capitalize">
                    {strategy.status}
                </span>
            </div>
            <p className="mt-1 text-sm text-foreground">{strategy.approach}</p>
            {strategy.superseded_reason && (
                <p className="mt-1 text-xs text-muted-foreground">
                    Superseded: {strategy.superseded_reason}
                </p>
            )}
        </li>
    );
}

function Badge({ children }: { children: string }) {
    return (
        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
            {children}
        </span>
    );
}
