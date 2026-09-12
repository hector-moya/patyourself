import { Form, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { LoopNotes } from '@/patyourself/loop-notes';
import { ActionLayer } from '@/patyourself/loops/action-layer';
import { Anatomy } from '@/patyourself/loops/anatomy';
import { cadenceLabel, currentCadenceLabel } from '@/patyourself/loops/cadence';
import { ExperimentCard } from '@/patyourself/loops/experiment-card';
import { LoopSettings } from '@/patyourself/loops/loop-settings';
import type { WorkflowOptionData } from '@/patyourself/loops/loop-settings';
import { NoteForm } from '@/patyourself/loops/note-form';
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
 * Ordered by how often each block is the reason the screen was opened: the
 * experiment first, the actions that carry it second, and the anatomy below
 * both — it is the loop's identity but it changes perhaps twice in a loop's
 * life, so it is disclosed behind the chain that names it rather than drawn.
 *
 * The timeline and the history sit on one screen deliberately — comparing what
 * was tried against what happened is the whole point of a notebook.
 *
 * Read-only: history is only ever appended to, and outcomes are logged from
 * the catch-up screen or the conversation. The actions here are configured,
 * not recorded against.
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
    // The version currently running. A `worked` verdict does not supersede —
    // ConcludeExperiment is explicit that such a version keeps running — so
    // "running" is status alone. This is the version the card is about, and
    // the one past experiments must not claim.
    const runningStrategy = strategies.find((s) => s.status === 'active');

    // The running version that has not been concluded — the only one the record
    // can still answer a review for. A `worked` verdict leaves a version active
    // while the question is closed, so status alone is not enough here.
    const activeExperiment =
        runningStrategy?.verdict === null ? runningStrategy : undefined;

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

                <ExperimentCard
                    current={currentVersion}
                    runningExperiment={runningStrategy}
                    interventionPoint={
                        intention.strategy?.intervention_point ?? null
                    }
                    previousRate={previousVersionRate(
                        experiments,
                        currentVersion,
                    )}
                />

                <section>
                    <SectionHeading>Actions</SectionHeading>
                    <ActionLayer
                        loopId={intention.id}
                        actions={actionSummaries}
                        workflow={intention.workflow}
                    />
                </section>

                <Anatomy
                    intention={intention}
                    interventionPoint={
                        intention.strategy?.intervention_point ?? null
                    }
                />

                <Reflection reflection={reflection} />

                <StrategyTimeline
                    strategies={strategies}
                    experiments={experiments}
                    activeVersion={runningStrategy?.version ?? null}
                />

                <OutcomeHistory
                    outcomes={outcomes}
                    total={outcomesTotal}
                    showingAll={showingAllHistory}
                    loopId={intention.id}
                />

                <section data-testid="notes" className="flex flex-col gap-6">
                    <NoteForm loopId={intention.id} />
                    <LoopNotes notes={notes} />
                </section>

                <LoopSettings
                    loopId={intention.id}
                    workflow={intention.workflow}
                    workflows={workflows}
                    endableExperiment={
                        activeExperiment?.is_under_review === false
                            ? activeExperiment
                            : undefined
                    }
                    canStartNext={Boolean(intention.strategy)}
                    currentCadence={currentCadenceLabel(
                        intention.active_action ?? null,
                    )}
                />
            </div>
        </CoachLayout>
    );
}

function Badge({ children }: { children: string }) {
    return (
        <span className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground capitalize">
            {children}
        </span>
    );
}
