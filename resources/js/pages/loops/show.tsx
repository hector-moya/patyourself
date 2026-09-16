import { Form, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';

import { update } from '@/actions/App/Http/Controllers/IntentionController';
import CoachLayout from '@/layouts/coach-layout';
import { BottomNav } from '@/patyourself/bottom-nav';
import { ActionLayer } from '@/patyourself/loops/action-layer';
import { Anatomy } from '@/patyourself/loops/anatomy';
import { cadenceLabel, currentCadenceLabel } from '@/patyourself/loops/cadence';
import { ExperimentCard } from '@/patyourself/loops/experiment-card';
import { LoopSettings } from '@/patyourself/loops/loop-settings';
import type { WorkflowOptionData } from '@/patyourself/loops/loop-settings';
import { Button } from '@/patyourself/primitives';
import {
    SectionHeading,
    StrategyTimeline,
} from '@/patyourself/strategy-timeline';
import type {
    ActionRecordData,
    CurrentVersionData,
    ExperimentData,
    IntentionData,
    StrategyData,
} from '@/patyourself/types';

/** Mirrors CreateLoopTool::AUTHORED_BY — the provenance stamp an MCP-created
 * loop's metadata carries, distinguishing it from one the user authored
 * directly in the app. */
const MCP_AUTHORED_BY = 'mcp-client';

interface LoopShowProps {
    intention: IntentionData;
    strategies: StrategyData[];
    /**
     * How many occasions the record holds. The only number this page carries
     * about what happened, and it is here to label the door rather than to be
     * read — everything it counts lives on `/loops/{loop}/record`.
     */
    outcomes_total: number;
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
    outcomes_total: outcomesTotal,
    actions,
    workflows = [],
    current_version: currentVersion = null,
    experiments = [],
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
    // the same kind of fact. The raw fields also pass through unformatted,
    // alongside the cadence, so the action layer's editor can open on the
    // action's own schedule instead of a set of defaults — see ActionEditor.
    const actionSummaries = actions.map((action) => ({
        id: action.id,
        title: action.title,
        cadence: cadenceLabel(action),
        scheduleKind: action.schedule_kind,
        time: action.time,
        recurrence: action.recurrence,
        anchor: action.anchor,
        date: action.date,
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

                {/* The timeline stays: a ladder of versions is a statement of
                    what was tried, which is what this page is for. What those
                    versions produced — the outcomes, the words written at the
                    time, the reflection, the notes — moved to the record, and
                    this is the one link across. */}
                <StrategyTimeline
                    strategies={strategies}
                    experiments={experiments}
                    activeVersion={runningStrategy?.version ?? null}
                />

                <RecordLink loopId={intention.id} total={outcomesTotal} />

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

/**
 * The way across to what happened.
 *
 * This page answers "what is this loop and what am I trying"; the record
 * answers "what came of it". Keeping one door here rather than a summary of the
 * record is the point of having split them — a count on this page would be the
 * first duplicated fact, and the two would drift.
 */
function RecordLink({ loopId, total }: { loopId: number; total: number }) {
    return (
        <section>
            <SectionHeading>The record</SectionHeading>
            <Link
                href={`/loops/${loopId}/record`}
                className="inline-flex items-center gap-2 text-sm text-muted-foreground underline underline-offset-2 hover:text-foreground"
            >
                {total === 0
                    ? 'Nothing logged yet'
                    : `Every occasion, the cuts and the reading (${total})`}
            </Link>
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
