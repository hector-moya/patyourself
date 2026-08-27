import { Form, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ExperimentHeader } from '@/patyourself/experiment-header';
import { LoopNotes } from '@/patyourself/loop-notes';
import { OutcomeHistory } from '@/patyourself/outcome-history';
import { Button } from '@/patyourself/primitives';
import { Reflection } from '@/patyourself/reflection';
import {
    SectionHeading,
    StrategyTimeline,
} from '@/patyourself/strategy-timeline';
import type {
    CurrentVersionData,
    ExperimentData,
    IntentionData,
    NoteData,
    OutcomeEntryData,
    ReflectionData,
    StrategyData,
} from '@/patyourself/types';

/** Mirrors CreateLoopTool::AUTHORED_BY — the provenance stamp an MCP-created
 * loop's metadata carries, distinguishing it from one the user authored
 * directly in the app. */
const MCP_AUTHORED_BY = 'mcp-client';

interface LoopShowProps {
    intention: IntentionData;
    strategies: StrategyData[];
    outcomes: OutcomeEntryData[];
    outcomes_total: number;
    showing_all_history: boolean;
    notes: NoteData[];
    /** The active experiment's own record. Null between experiments. */
    current_version?: CurrentVersionData | null;
    /** One rung per version, oldest first. */
    experiments?: ExperimentData[];
    /** The loop's rolling narrative, written through write-reflection. */
    reflection?: ReflectionData | null;
}

/**
 * The rate of the version immediately before the current one, for the header's
 * comparison.
 *
 * Only a version that produced a decision counts — comparing against a version
 * that was never tested would invent a trend out of nothing. Returns null when
 * there is no such version, and the header then omits the delta entirely rather
 * than rendering a placeholder.
 */
function previousVersionRate(
    experiments: ExperimentData[],
    current: CurrentVersionData | null,
): number | null {
    if (current === null) {
        return null;
    }

    const previous = experiments
        .filter((experiment) => experiment.version < current.version)
        .sort((a, b) => b.version - a.version)[0];

    if (previous === undefined) {
        return null;
    }

    const decided = previous.totals.completed + previous.totals.failed;

    return decided === 0
        ? null
        : Math.round((previous.totals.completed / decided) * 100);
}

/**
 * The lab record for one loop: the habit anatomy (cue → craving → response →
 * reward, with the stage the active strategy intervenes on highlighted), the
 * versioned experiment timeline, the outcomes those experiments produced, and
 * the notes taken alongside them.
 *
 * The timeline and the history sit on one screen deliberately — comparing what
 * was tried against what happened is the whole point of a notebook.
 *
 * Read-only: history is only ever appended to, and outcomes are logged from the
 * catch-up screen or the conversation.
 */
export default function LoopShow({
    intention,
    strategies,
    outcomes,
    outcomes_total: outcomesTotal,
    showing_all_history: showingAllHistory,
    notes,
    current_version: currentVersion = null,
    experiments = [],
    reflection = null,
}: LoopShowProps) {
    const back = (
        <Link
            href="/loops"
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

                {intention.status === 'paused' && (
                    <Form
                        {...update.form(intention.id)}
                        className="flex flex-col gap-2"
                    >
                        {({ processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="status"
                                    value="active"
                                />
                                <Button type="submit" disabled={processing}>
                                    Activate loop
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    {intention.metadata?.authored_by ===
                                        MCP_AUTHORED_BY &&
                                        'Claude drafted this loop. '}
                                    Activating it starts its schedule and
                                    notifications.
                                </p>
                            </>
                        )}
                    </Form>
                )}

                {intention.description && (
                    <p className="text-sm text-muted-foreground">
                        {intention.description}
                    </p>
                )}

                {/* What is being tested and whether it is holding, before any
                    scrolling. The reflection follows it because reading what
                    the record shows is the point of opening this screen; the
                    anatomy sits below both because it changes rarely. */}
                <ExperimentHeader
                    current={currentVersion}
                    interventionPoint={
                        intention.strategy?.intervention_point ?? null
                    }
                    previousRate={previousVersionRate(
                        experiments,
                        currentVersion,
                    )}
                />

                <Reflection reflection={reflection} />

                <Anatomy
                    intention={intention}
                    interventionPoint={
                        intention.strategy?.intervention_point ?? null
                    }
                />

                <StrategyTimeline
                    strategies={strategies}
                    experiments={experiments}
                />

                <OutcomeHistory
                    outcomes={outcomes}
                    total={outcomesTotal}
                    showingAll={showingAllHistory}
                    loopId={intention.id}
                />

                <LoopNotes notes={notes} />
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

function Badge({ children }: { children: string }) {
    return (
        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
            {children}
        </span>
    );
}
