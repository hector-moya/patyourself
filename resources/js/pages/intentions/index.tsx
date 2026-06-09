import { Link } from '@inertiajs/react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { Icon } from '@/patyourself/primitives';
import type { IntentionData } from '@/patyourself/types';

interface LoopsIndexProps {
    intentions: IntentionData[];
}

/**
 * Loops list — every loop the user is working, status at a glance, each tapping
 * through to its detail screen. Active loops surface first (ordered server-side).
 */
export default function LoopsIndex({ intentions }: LoopsIndexProps) {
    const activeCount = intentions.filter(
        (loop) => loop.status === 'active',
    ).length;

    return (
        <CoachLayout title="Loops" bottomNav={<BottomNav />}>
            {intentions.length === 0 ? (
                <EmptyState />
            ) : (
                <>
                    <p className="mb-3 text-sm text-muted-foreground">
                        {intentions.length}{' '}
                        {intentions.length === 1 ? 'loop' : 'loops'}
                        {activeCount > 0 && ` · ${activeCount} active`}
                    </p>
                    <ul className="flex flex-col gap-2">
                        {intentions.map((loop) => (
                            <li key={loop.id}>
                                <LoopRow loop={loop} />
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </CoachLayout>
    );
}

function LoopRow({ loop }: { loop: IntentionData }) {
    const build = loop.type === 'build';
    const tactic = loop.strategy?.approach ?? loop.response;

    return (
        <Link
            href={`/intentions/${loop.id}`}
            className="flex items-center gap-3 rounded-xl border border-border bg-card p-3 transition-colors hover:border-foreground/20 hover:bg-accent/40"
        >
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                    build
                        ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                        : 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
                )}
                aria-hidden="true"
            >
                <Icon
                    name={build ? 'trending-up' : 'trending-down'}
                    size={18}
                />
            </span>

            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                    <span className="truncate font-semibold text-foreground">
                        {loop.title}
                    </span>
                    {loop.strategy && (
                        <span className="shrink-0 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground capitalize">
                            {loop.strategy.intervention_point}
                        </span>
                    )}
                </span>
                <span className="mt-0.5 block truncate text-sm text-muted-foreground">
                    {tactic}
                </span>
            </span>

            <StatusPill status={loop.status} />
        </Link>
    );
}

const STATUS_DOT: Record<string, string> = {
    active: 'bg-emerald-500',
    paused: 'bg-amber-500',
    completed: 'bg-sky-500',
    archived: 'bg-zinc-400',
};

function StatusPill({ status }: { status: string }) {
    return (
        <span className="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground capitalize">
            <span
                className={cn(
                    'size-2 rounded-full',
                    STATUS_DOT[status] ?? 'bg-zinc-400',
                )}
                aria-hidden="true"
            />
            {status}
        </span>
    );
}

function EmptyState() {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-2 text-center">
            <h2 className="text-lg font-semibold text-foreground">
                No loops yet
            </h2>
            <p className="max-w-xs text-sm text-muted-foreground">
                Start a conversation with the Coach and it will author your
                first habit loop for you.
            </p>
            <Link
                href="/dashboard"
                className="mt-2 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
            >
                Talk to the Coach
            </Link>
        </div>
    );
}
