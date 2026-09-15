/**
 * Client-side shapes mirroring the server API resources (IntentionResource,
 * StrategyResource). Loops are authored via the MCP `create-loop` tool and
 * the server validates them; the UI only renders them.
 */

/** Type-only, so this does not become a runtime cycle: `workflows.ts` imports
 *  the surfaces it registers, and one of those reads back from here. */
import type { WorkflowConfigRow } from '@/patyourself/workflows';

export interface StrategyData {
    id: number;
    version: number;
    status: string;
    intervention_point: string;
    approach: string;
    rationale: string | null;
    change_reason: string | null;
    superseded_reason: string | null;
    /** The experiment framing. Always present — StrategyResource sends these keys unconditionally. */
    review_at: string | null;
    verdict: string | null;
    verdict_note: string | null;
    day_of_experiment: number;
    planned_days: number | null;
    is_under_review: boolean;
    /** How many outcomes were recorded under this version. Absent when the caller did not count them. */
    outcomes_recorded?: number;
    parent_strategy_id: number | null;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
}

/** The active-strategy summary embedded in an IntentionResource. */
export interface ActiveStrategySummary {
    intervention_point: string;
    approach: string;
    rationale: string | null;
    version: number;
    /** The experiment's state, so a list of loops answers "what am I running"
     * without opening each one. `planned_days` is null for an open-ended
     * experiment — a legitimate state that must never render as a countdown. */
    day_of_experiment: number;
    planned_days: number | null;
    is_under_review: boolean;
}

/**
 * The loop's rolling narrative, written through the `write-reflection` MCP tool.
 *
 * Claude supplies the words; the window and the occasion count are taken from
 * the record. Rendering the provenance is what makes it evidence rather than an
 * assertion, so the three are carried together.
 */
export interface ReflectionData {
    content: string;
    window_start: string | null;
    window_end: string | null;
    events_count: number | null;
}

/**
 * One rung of the experiment ladder — LoopProgress::experimentsFor().
 *
 * Logs attribute to a version through `actions.strategy_id`, so the totals here
 * belong to the experiment that was running when they were logged, not to
 * whichever version is active now.
 */
export interface ExperimentData {
    strategy_id: number;
    version: number;
    status: string;
    intervention_point: string;
    approach: string;
    hypothesis: string | null;
    started_at: string;
    review_at: string | null;
    day_of_experiment: number;
    planned_days: number | null;
    is_under_review: boolean;
    verdict: string | null;
    verdict_note: string | null;
    outcomes: Array<{
        outcome: string;
        reason: string | null;
        logged_at: string;
    }>;
    totals: { completed: number; failed: number; skipped: number };
}

/**
 * The active experiment's own record — LoopProgress::forCurrentVersion().
 *
 * Null when no version is active, which is a good state rather than an empty
 * one: a loop running indefinitely with no experiment under test is a success,
 * and logging is never gated on one existing.
 */
export interface CurrentVersionData {
    version: number;
    started_at: string;
    day_of_experiment: number;
    planned_days: number | null;
    is_under_review: boolean;
    verdict: string | null;
    streak: { outcome: string | null; length: number };
    completion_rate: number | null;
    totals: { completed: number; failed: number; skipped: number };
    last_logged_at: string | null;
}

/** The loggable action embedded in an IntentionResource (the card's quick-log target). */
export interface ActiveActionData {
    id: number;
    title: string;
    description: string | null;
    /** The next occasion still awaiting an outcome, or null when today has none left. */
    next_occurrence_at: string | null;
    recurrence: string | null;
    schedule_kind: 'clock' | 'anchored' | null;
    anchor: string | null;
}

/**
 * A live (non-archived) action on the loop, as listed on the lab record's
 * action layer. Carries the raw scheduling fields rather than a pre-formatted
 * cadence string so the client can apply the same null-safe cadence rules
 * `currentCadenceLabel` already established, instead of a second formatter.
 */
export interface ActionRecordData {
    id: number;
    title: string;
    next_occurrence_at: string | null;
    recurrence: string | null;
    schedule_kind: 'clock' | 'anchored' | null;
    anchor: string | null;
    /** The anchor's time of day in the owner's zone, `HH:MM`. Null for a
     *  cue-anchored action, which has no clock time. */
    time: string | null;
    /** The anchor's date in the owner's zone, `YYYY-MM-DD`. Null for a
     *  cue-anchored action, which has no anchor at all. Pre-formatted by the
     *  server because the editor's date input needs this exact string and
     *  re-deriving it from an ISO instant in the browser's zone moves it a day
     *  for anyone west of the owner. The cadence line names this same string
     *  for the same reason — see cadence.ts. */
    date: string | null;
    /** The anchor as an instant, ISO 8601 in the owner's zone. The cadence line
     *  compares it against now to tell a series that has not begun from one
     *  whose grid is simply exhausted for today — both report no next
     *  occurrence, and they mean opposite things. Compared, never displayed:
     *  `date` and `time` are what get rendered. */
    starts_at: string | null;
    /**
     * The action's configuration under the loop's workflow — for gym, its
     * routine. Null when the loop has no workflow that configures actions,
     * which is not the same as an empty routine: see `WorkflowConfig`.
     */
    routine?: WorkflowConfigRow[] | null;
}

export interface IntentionData {
    id: number;
    title: string;
    description: string | null;
    type: string;
    status: string;
    /** Which recording surface this loop uses. Null for the plain screen. */
    workflow: string | null;
    cue: string;
    craving: string;
    response: string;
    reward: string;
    metadata: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
    strategy?: ActiveStrategySummary | null;
    active_action?: ActiveActionData | null;
}

/**
 * The outcome a user records against an action. Mirrors ActionLog's
 * OUTCOME_* constants on the server (the only values LogAction accepts).
 */
export type LogOutcome = 'completed' | 'failed' | 'skipped';

/** The small, closed structured set recorded beside an outcome's free text. */
export interface OutcomeContextFields {
    place?: string | null;
    with_others?: boolean | null;
    preceded_by?: string | null;
}

/**
 * One recorded outcome on a loop's record. `occurred_at` is when the occasion
 * happened; `logged_at` is when it was typed. They differ whenever the user
 * caught up after the fact, which is the ordinary case.
 */
export interface OutcomeEntryData {
    id: number;
    occurred_at: string;
    logged_at: string;
    action_id: number;
    action_title: string;
    outcome: LogOutcome | string;
    /** The user's own words, unchanged. */
    reason: string | null;
    context: string | null;
    context_fields: OutcomeContextFields | null;
    strategy_version: number | null;
}

/** One occasion that has passed with no outcome yet — a row on the catch-up list. */
export interface PendingOccurrenceData {
    id: number;
    loop_id: number;
    loop_title: string;
    /** The loop's recording surface. Null for the plain screen. */
    workflow: string | null;
    action_id: number;
    action_title: string;
    scheduled_for: string;
}

/** An observation attached to the loop and to no occasion. */
export interface NoteData {
    id: number;
    body: string;
    noted_at: string;
}

/** One delivered cue in the inbox (mirrors InboxController's mapped payload). */
export interface NotificationData {
    id: string;
    type?: 'action_due' | 'strategy_revised';
    action_id: number | null;
    intention_id: number | null;
    title: string | null;
    fired_at: string | null;
    change_reason?: string | null;
    approach?: string | null;
    read_at: string | null;
}

/** One outcome mark in a progress sparkline. Mirrors ActionLog's OUTCOME_* values. */
export type OutcomeMark = 'completed' | 'failed' | 'skipped';

/** The active strategy's leading run (from OutcomeStreak), as shown on a progress card. */
export interface LoopStreak {
    outcome: 'completed' | 'failed' | null;
    length: number;
}

/**
 * One active loop's metric card on the progress index (mirrors
 * ProgressController@index).
 *
 * The figures are the *running version's*, not the loop's whole lifetime —
 * which is what makes `previous_version` a fair comparison rather than one
 * against a number that already contains it. A loop between experiments has
 * no version to be about, so `version` is null and the figures fall back to
 * the lifetime record.
 */
export interface LoopProgressCard {
    id: number;
    title: string;
    type: string;
    /** The running version, or null between experiments. */
    version: number | null;
    day_of_experiment: number | null;
    streak: LoopStreak;
    completion_rate: number | null; // 0–100, null when no decided logs
    totals: { completed: number; failed: number; skipped: number };
    recent: OutcomeMark[]; // oldest → newest, max 10
    /**
     * The last version before this one that produced a decision, and the rate
     * it held at. Null for a first version, and for one whose predecessors
     * were replaced before anything was logged against them.
     */
    previous_version: { version: number; rate: number } | null;
    last_logged_at: string | null;
    summary_excerpt: string | null;
}

/**
 * What the whole record adds up to, for the line at the top of the screen.
 * Counts only loops that have decided something — a loop with nothing logged
 * has neither held nor failed anything.
 */
export interface ProgressSummary {
    held: number;
    decided: number;
    skipped: number;
    versions_running: number;
    /** Running versions beating the one they replaced. Counted, not assumed. */
    versions_ahead: number;
}

/** The same metric block on the detail screen (no index-only excerpt). */
export type LoopProgressDetail = Omit<LoopProgressCard, 'summary_excerpt'>;
