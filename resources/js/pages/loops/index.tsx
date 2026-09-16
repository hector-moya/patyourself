import { Link, router } from '@inertiajs/react';
import type { CSSProperties, FormEvent } from 'react';
import { Fragment, useState } from 'react';

import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { Icon } from '@/patyourself/primitives';
import type { ActiveStrategySummary, IntentionData } from '@/patyourself/types';

/** How much one loop's record adds up to. `decided` excludes skips. */
interface RecordCount {
    held: number;
    decided: number;
}

interface LoopsIndexProps {
    intentions: IntentionData[];
    filters: { status: string | null; q: string | null };
    /** Keyed by loop id. A loop with nothing decided is simply absent. */
    records?: Record<number, RecordCount>;
}

const STATUSES = ['active', 'paused', 'completed', 'archived'] as const;

/** The chain, in order, and the token each stage paints with. */
const STAGES = ['cue', 'craving', 'response', 'reward'] as const;

/**
 * Loops list — every loop the user is working, each one carrying its chain, the
 * intervention currently running on it, and what that intervention has come to.
 *
 * Grouped by whether the loop is running rather than listed flat: a paused loop
 * is not a worse active one, and the two answer different questions. Active
 * loops surface first within the list (ordered server-side).
 */
export default function LoopsIndex({
    intentions,
    filters,
    records = {},
}: LoopsIndexProps) {
    const active = intentions.filter((loop) => loop.status === 'active');
    const rest = intentions.filter((loop) => loop.status !== 'active');
    const filtering = filters.status !== null || filters.q !== null;

    return (
        <CoachLayout
            title="Loops"
            // Header slot, so the count stays put while the grid scrolls — the
            // same arrangement the Today screen uses.
            header={
                <div className="t-head">
                    <div>
                        <p className="t-date">Your record</p>
                        <h1 className="t-day">Loops</h1>
                    </div>
                    {intentions.length > 0 && (
                        <p className="t-tally">
                            {intentions.length}{' '}
                            {intentions.length === 1 ? 'loop' : 'loops'}
                            {active.length > 0 && ` · ${active.length} active`}
                        </p>
                    )}
                </div>
            }
            flush
            bottomNav={<BottomNav />}
        >
            <div className="t-body">
                <div className="t-col t-col--wide">
                    <FilterBar filters={filters} />

                    {intentions.length === 0 ? (
                        filtering ? (
                            <NoMatches />
                        ) : (
                            <EmptyState />
                        )
                    ) : (
                        <>
                            <div className="l-tally">
                                <p>Ordered by what is running</p>
                                {/* Plain link, no count and no badge. An
                                    unlogged occasion never expires, so a number
                                    here would turn the record into a
                                    scoreboard. */}
                                <Link
                                    href="/catch-up"
                                    className="py-btn py-btn--secondary py-btn--sm"
                                >
                                    <Icon name="history" size={16} />
                                    Catch up
                                </Link>
                            </div>

                            {active.length > 0 && (
                                <>
                                    <h2 className="l-sech">Running now</h2>
                                    <LoopGrid
                                        loops={active}
                                        records={records}
                                    />
                                </>
                            )}
                            {rest.length > 0 && (
                                <>
                                    <h2 className="l-sech">Not running</h2>
                                    <LoopGrid loops={rest} records={records} />
                                </>
                            )}
                        </>
                    )}
                </div>
            </div>
        </CoachLayout>
    );
}

function LoopGrid({
    loops,
    records,
}: {
    loops: IntentionData[];
    records: Record<number, RecordCount>;
}) {
    return (
        <ul className="l-grid">
            {loops.map((loop) => (
                <li key={loop.id}>
                    <LoopCard loop={loop} record={records[loop.id]} />
                </li>
            ))}
        </ul>
    );
}

/**
 * Status chips and a search box. Search covers the whole chain server-side —
 * the cue is often what you remember about a loop.
 */
function FilterBar({ filters }: { filters: LoopsIndexProps['filters'] }) {
    const [term, setTerm] = useState(filters.q ?? '');

    const submit = (event: FormEvent) => {
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
        <div className="l-tools">
            <form onSubmit={submit}>
                <label className="l-search">
                    <Icon name="search" size={17} />
                    <input
                        type="search"
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Search the title or the chain"
                        aria-label="Search loops"
                    />
                </label>
            </form>
            <div className="l-filters">
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
            aria-pressed={active}
            className={cn('py-chip py-chip--btn', active && 'is-active')}
        >
            {label}
        </Link>
    );
}

/**
 * One loop, at a glance: what it is called, whether it is running, the chain it
 * describes, and the approach currently being tried on it.
 *
 * The card is bordered in the accent of the stage the running strategy
 * intervenes on, and the same stage is underlined in the chain — so the colour
 * is never decoration, it always names the same thing twice. A loop with no
 * running experiment has no accent and takes the plain border; there is no
 * stage to point at, and picking one anyway would be a claim the record does
 * not make.
 */
function LoopCard({
    loop,
    record,
}: {
    loop: IntentionData;
    record?: RecordCount;
}) {
    const strategy = loop.strategy ?? null;
    const acts = strategy?.intervention_point ?? null;
    const quiet = loop.status !== 'active';

    return (
        // A div, not a link. The card has two destinations — what the loop is,
        // and what its record shows — and an anchor cannot contain another
        // anchor. The title's link is stretched across the card instead (see
        // `.l-open::after`), so the whole card still opens the loop while the
        // footer link stays a real, separately focusable link.
        <div
            className={cn('l-card', quiet && 'is-quiet')}
            style={
                acts === null
                    ? undefined
                    : ({
                          '--accent-line': `var(--${acts})`,
                      } as CSSProperties)
            }
        >
            <div className="l-top">
                <h3 className="l-title">
                    <Link href={`/loops/${loop.id}`} className="l-open">
                        {loop.title}
                    </Link>
                </h3>
                <span className={`l-status is-${loop.status}`}>
                    <i />
                    {loop.status}
                </span>
            </div>

            <Chain acts={acts} />

            <p className="l-approach">{strategy?.approach ?? loop.response}</p>

            <p data-testid={`loop-experiment-${loop.id}`} className="l-meta">
                {loop.type} · {experimentState(strategy)}
            </p>

            {strategy?.is_under_review && (
                <div className="l-verdict">
                    <p>This version has run its course. Did it hold?</p>
                    {/* Not a control — the card's own link leads to the verdict
                        form. It names where the card goes. */}
                    <span className="py-btn py-btn--secondary py-btn--sm">
                        Give it a verdict
                    </span>
                </div>
            )}

            <div className="l-foot">
                <Link
                    href={`/loops/${loop.id}/record`}
                    className="l-record"
                    aria-label={`The record for ${loop.title}`}
                >
                    The record · {recordCount(record)}
                </Link>
            </div>
        </div>
    );
}

/** "12 of 20 held", or the plain truth when there is nothing to count. */
function recordCount(record?: RecordCount): string {
    return record === undefined || record.decided === 0
        ? 'nothing logged yet'
        : `${record.held} of ${record.decided} held`;
}

/** Cue → craving → response → reward, with the acting stage picked out. */
function Chain({ acts }: { acts: string | null }) {
    return (
        <p className="l-chain">
            {STAGES.map((stage, index) => (
                <Fragment key={stage}>
                    {index > 0 && <s aria-hidden="true">→</s>}
                    {stage === acts ? (
                        <b className="capitalize">{stage}</b>
                    ) : (
                        <span className="capitalize">{stage}</span>
                    )}
                </Fragment>
            ))}
        </p>
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

function EmptyState() {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-2 py-12 text-center">
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
        <div className="flex flex-1 flex-col items-center justify-center gap-2 py-12 text-center">
            <p className="t-done">No loops match that.</p>
            <Link
                href="/loops"
                className="text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground"
            >
                Clear the filters
            </Link>
        </div>
    );
}
