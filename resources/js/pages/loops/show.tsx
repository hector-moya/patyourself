import { Form, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ExperimentHeader } from '@/patyourself/experiment-header';
import { LoopNotes } from '@/patyourself/loop-notes';
import { ActionLayer } from '@/patyourself/loops/action-layer';
import { Anatomy } from '@/patyourself/loops/anatomy';
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

/** One workflow the loop may record through, straight from the server registry. */
export interface WorkflowOptionData {
    name: string;
    label: string;
}

interface LoopShowProps {
    intention: IntentionData;
    strategies: StrategyData[];
    outcomes: OutcomeEntryData[];
    outcomes_total: number;
    showing_all_history: boolean;
    notes: NoteData[];
    /** Every live (non-archived) action on the loop, for the action layer. */
    actions: ActionRecordData[];
    /**
     * What this loop could record through. Drawn from the server registry —
     * `config/workflows.php` is what decides which names `UpdateIntentionRequest`
     * accepts, so a second list held in the client could only ever drift out of
     * agreement with it.
     */
    workflows?: WorkflowOptionData[];
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
    workflows = [],
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
        routine: action.routine ?? null,
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
                        workflow={intention.workflow}
                    />
                </section>

                <WorkflowPicker
                    loopId={intention.id}
                    current={intention.workflow}
                    workflows={workflows}
                />

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
 * What this loop records, on top of whether it happened.
 *
 * Behind a disclosure, and "Nothing extra" is the first option and the one a
 * loop already has: recording nothing extra is what almost every loop does,
 * forever, and the control must not read as a field left blank. The summary
 * line says so in plain words when nothing is set, rather than showing an
 * empty slot inviting one.
 *
 * The options come from the server registry and are never typed. A free-text
 * tag was the earlier answer and it failed silently on `Gym`, `gimnasio` or a
 * trailing space, with nothing on screen to say why — see
 * `UpdateIntentionRequest`, which validates against the same list this is
 * drawn from.
 */
function WorkflowPicker({
    loopId,
    current,
    workflows,
}: {
    loopId: number;
    current: string | null;
    workflows: WorkflowOptionData[];
}) {
    if (workflows.length === 0) {
        return null;
    }

    const currentLabel =
        workflows.find((workflow) => workflow.name === current)?.label ?? null;

    return (
        <details data-testid="workflow-picker">
            <summary className="ds-label cursor-pointer">
                Recording
                <span className="ml-1 font-normal text-muted-foreground normal-case">
                    ·{' '}
                    {currentLabel === null
                        ? 'nothing extra'
                        : currentLabel.toLowerCase()}
                </span>
            </summary>

            <Form
                {...update.form(loopId)}
                options={{ preserveScroll: true }}
                className="mt-3 flex flex-col gap-2"
            >
                {({ processing }) => (
                    <>
                        <label htmlFor="loop-workflow" className="sr-only">
                            What this loop records
                        </label>
                        <select
                            id="loop-workflow"
                            name="workflow"
                            defaultValue={current ?? ''}
                            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        >
                            <option value="">Nothing extra</option>
                            {workflows.map((workflow) => (
                                <option
                                    key={workflow.name}
                                    value={workflow.name}
                                >
                                    {workflow.label}
                                </option>
                            ))}
                        </select>

                        <p className="text-xs text-muted-foreground">
                            Most loops record nothing extra — the outcome is the
                            whole record. A workflow adds somewhere to write
                            down what happened during an occasion. Changing this
                            keeps everything already written.
                        </p>

                        <div className="self-start">
                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </details>
    );
}

function Badge({ children }: { children: string }) {
    return (
        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
            {children}
        </span>
    );
}
