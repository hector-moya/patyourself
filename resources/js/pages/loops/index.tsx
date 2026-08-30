import { Link, router } from '@inertiajs/react';
import { useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { Icon } from '@/patyourself/primitives';
import type { ActiveStrategySummary, IntentionData } from '@/patyourself/types';

interface LoopsIndexProps {
    intentions: IntentionData[];
    filters: { status: string | null; q: string | null };
}

const STATUSES = ['active', 'paused', 'completed', 'archived'] as const;

/**
 * Loops list — every loop the user is working, status at a glance, each tapping
 * through to its detail screen. Active loops surface first (ordered server-side).
 */
export default function LoopsIndex({ intentions, filters }: LoopsIndexProps) {
    const activeCount = intentions.filter(
        (loop) => loop.status === 'active',
    ).length;
    const filtering = filters.status !== null || filters.q !== null;

    return (
        <CoachLayout title="Loops" bottomNav={<BottomNav />} wide>
            <FilterBar filters={filters} />
            {intentions.length === 0 ? (
                filtering ? (
                    <NoMatches />
                ) : (
                    <EmptyState />
                )
            ) : (
                <>
                    <div className="mb-3 flex items-baseline justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            {intentions.length}{' '}
                            {intentions.length === 1 ? 'loop' : 'loops'}
                            {activeCount > 0 && ` · ${activeCount} active`}
                        </p>
                        {/* Plain text, no count and no badge. An unlogged
                            occasion never expires, so surfacing a number here
                            would turn the record into a scoreboard. */}
                        <Link
                            href="/catch-up"
                            className="shrink-0 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
                        >
                            Catch up
                        </Link>
                    </div>
                    <ul className="grid grid-cols-1 gap-2 md:grid-cols-2 md:gap-3">
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

/**
 * Status chips and a search box. Search covers the whole chain server-side —
 * the cue is often what you remember about a loop.
 */
function FilterBar({ filters }: { filters: LoopsIndexProps['filters'] }) {
    const [term, setTerm] = useState(filters.q ?? '');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        router.get(
            '/loops',
            {
                ...(filters.status ? { status: filters.status } : {}),
                ...(term.trim() ? { q: term.trim() } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <div className="mb-3 flex flex-col gap-2">
            <form onSubmit={submit}>
                <input
                    type="search"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder="Search the title or the chain"
                    aria-label="Search loops"
                    className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                />
            </form>
            <div className="flex flex-wrap gap-1.5">
                <FilterChip
                    label="All"
                    href={hrefFor(null, filters.q)}
                    active={filters.status === null}
                />
                {STATUSES.map((status) => (
                    <FilterChip
                        key={status}
                        label={status}
                        href={hrefFor(status, filters.q)}
                        active={filters.status === status}
                    />
                ))}
            </div>
        </div>
    );
}

function hrefFor(status: string | null, q: string | null): string {
    const params = new URLSearchParams();

    if (status) {
        params.set('status', status);
    }

    if (q) {
        params.set('q', q);
    }

    const query = params.toString();

    return query ? `/loops?${query}` : '/loops';
}

function FilterChip({
    label,
    href,
    active,
}: {
    label: string;
    href: string;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            preserveScroll
            className={cn(
                'rounded-full border px-2.5 py-1 text-xs capitalize transition-colors',
                active
                    ? 'border-foreground/30 bg-accent text-foreground'
                    : 'border-border text-muted-foreground hover:text-foreground',
            )}
        >
            {label}
        </Link>
    );
}

function LoopRow({ loop }: { loop: IntentionData }) {
    const build = loop.type === 'build';
    const tactic = loop.strategy?.approach ?? loop.response;

    return (
        <Link
            href={`/loops/${loop.id}`}
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
                {/* The experiment's state, so the list answers "what am I
                    running" without opening anything. */}
                <span
                    data-testid={`loop-experiment-${loop.id}`}
                    className="mt-0.5 block font-mono text-[10px] tracking-wide text-muted-foreground uppercase"
                >
                    {experimentState(loop.strategy ?? null)}
                </span>
            </span>

            <StatusPill status={loop.status} />
        </Link>
    );
}

/**
 * The experiment's state in one line.
 *
 * `is_under_review` is tested before the day count, so a version past its review
 * date asks for a verdict rather than reporting an overrun. A null
 * `planned_days` is open-ended and never renders as a countdown, and a loop with
 * no experiment reads as a good state — logging continues either way.
 */
function experimentState(strategy: ActiveStrategySummary | null): string {
    if (strategy === null) {
        return 'no experiment · logging';
    }

    if (strategy.is_under_review) {
        return `v${strategy.version} · ready for a verdict`;
    }

    return strategy.planned_days === null
        ? `v${strategy.version} · day ${strategy.day_of_experiment} · open-ended`
        : `v${strategy.version} · day ${strategy.day_of_experiment} of ${strategy.planned_days}`;
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
        <div className="flex flex-1 flex-col items-center justify-center gap-2 text-center">
            <h2 className="text-lg font-semibold text-foreground">
                No loops yet
            </h2>
            <p className="max-w-xs text-sm text-muted-foreground">
                Loops are created by talking to Claude through the PatYourSelf
                connector, then reviewed here.
            </p>
        </div>
    );
}

function NoMatches() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 text-center">
            <p className="text-sm text-muted-foreground">
                No loops match that.
            </p>
            <Link
                href="/loops"
                className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
            >
                Clear the filters
            </Link>
        </div>
    );
}
