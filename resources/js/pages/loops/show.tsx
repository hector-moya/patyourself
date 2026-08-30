import { Form, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import CoachLayout from '@/layouts/coach-layout';
import { cn } from '@/lib/utils';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ExperimentHeader } from '@/patyourself/experiment-header';
import { LoopNotes } from '@/patyourself/loop-notes';
import { ActionLayer } from '@/patyourself/loops/action-layer';
import { cadenceLabel, currentCadenceLabel } from '@/patyourself/loops/cadence';
import { ConcludeExperimentForm } from '@/patyourself/loops/conclude-experiment-form';
import { NoteForm } from '@/patyourself/loops/note-form';
import { StartExperimentForm } from '@/patyourself/loops/start-experiment-form';
import { OutcomeHistory } from '@/patyourself/outcome-history';
import { Button } from '@/patyourself/primitives';
import { Reflection } from '@/patyourself/reflection';
import {
    SectionHeading,
    StrategyTimeline,
} from '@/patyourself/strategy-timeline';
import type {
    ActionRecordData,
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
    /** Every live (non-archived) action on the loop, for the action layer. */
    actions: ActionRecordData[];
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
    actions,
    current_version: currentVersion = null,
    experiments = [],
    reflection = null,
}: LoopShowProps) {
    // The active version that has not yet been concluded — the one the record
    // can still answer a review for. A `worked` verdict keeps a version active,
    // so `status === 'active'` alone is not enough; only the absence of a
    // verdict means the question is still open.
    const activeExperiment = strategies.find(
        (s) => s.status === 'active' && s.verdict === null,
    );

    // The raw scheduling fields are turned into a display cadence here, with
    // the same rules `currentCadenceLabel` uses for the active action below —
    // one formatter, so the two never drift into disagreeing descriptions of
    // the same kind of fact.
    const actionSummaries = actions.map((action) => ({
        id: action.id,
        title: action.title,
        cadence: cadenceLabel(action),
    }));

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

                {activeExperiment && (
                    <ConcludeExperimentForm
                        strategyId={activeExperiment.id}
                        isUnderReview={activeExperiment.is_under_review}
                    />
                )}

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

                <section>
                    <SectionHeading>Actions</SectionHeading>
                    <ActionLayer
                        loopId={intention.id}
                        actions={actionSummaries}
                    />
                </section>

                {/* Behind a disclosure, so starting the next experiment does
                    not compete with the record for attention — it is only
                    possible to reach when a strategy is active to supersede. */}
                {intention.strategy && (
                    <details>
                        <summary className="ds-label cursor-pointer">
                            Start the next experiment
                        </summary>
                        <div className="mt-3">
                            <StartExperimentForm
                                loopId={intention.id}
                                currentCadence={currentCadenceLabel(
                                    intention.active_action ?? null,
                                )}
                            />
                        </div>
                    </details>
                )}

                <OutcomeHistory
                    outcomes={outcomes}
                    total={outcomesTotal}
                    showingAll={showingAllHistory}
                    loopId={intention.id}
                />

                <NoteForm loopId={intention.id} />
                <LoopNotes notes={notes} />
            </div>
        </CoachLayout>
    );
}

/**
 * Each stage carries its own accent, defined as `--stage-*` in patyourself.css.
 * The four exist in the palette and are named for exactly these stages; painting
 * the intervention point with the generic primary threw that away and made the
 * chain read as one undifferentiated list.
 */
const STAGES = [
    { key: 'cue', label: 'Cue', hint: 'the trigger', accent: 'cue' },
    {
        key: 'craving',
        label: 'Craving',
        hint: 'the motivation',
        accent: 'craving',
    },
    {
        key: 'response',
        label: 'Response',
        hint: 'the behaviour',
        accent: 'response',
    },
    { key: 'reward', label: 'Reward', hint: 'the payoff', accent: 'reward' },
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
                                    className="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
                                    style={{
                                        borderColor: `var(--stage-${stage.accent})`,
                                        backgroundColor: acts
                                            ? `var(--stage-${stage.accent})`
                                            : `var(--stage-${stage.accent}-soft)`,
                                        color: acts
                                            ? 'var(--stage-on-accent, #FFF8F3)'
                                            : `var(--stage-${stage.accent})`,
                                    }}
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
                                    !acts && 'border-border',
                                )}
                                style={
                                    acts
                                        ? {
                                              borderColor: `var(--stage-${stage.accent})`,
                                              backgroundColor: `var(--stage-${stage.accent}-soft)`,
                                          }
                                        : undefined
                                }
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span
                                        className="text-xs font-semibold tracking-wide uppercase"
                                        style={{
                                            color: `var(--stage-${stage.accent})`,
                                        }}
                                    >
                                        {stage.label}
                                        <span className="ml-1 font-normal text-muted-foreground/70 normal-case">
                                            · {stage.hint}
                                        </span>
                                    </span>
                                    {acts && (
                                        <span
                                            className="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-medium"
                                            style={{
                                                backgroundColor: `var(--stage-${stage.accent}-soft)`,
                                                color: `var(--stage-${stage.accent})`,
                                            }}
                                        >
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
