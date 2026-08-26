/**
 * Client-side shapes mirroring the server API resources (IntentionResource,
 * StrategyResource). Loops are authored via the MCP `create-loop` tool and
 * the server validates them; the UI only renders them.
 */

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
}

/** The loggable action embedded in an IntentionResource (the card's quick-log target). */
export interface ActiveActionData {
    id: number;
    title: string;
    description: string | null;
    status: string;
    scheduled_for: string | null;
    recurrence: string | null;
    schedule_kind: 'clock' | 'anchored' | null;
    anchor: string | null;
}

export interface IntentionData {
    id: number;
    title: string;
    description: string | null;
    type: string;
    status: string;
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

/** One active loop's metric card on the progress index (mirrors ProgressController@index). */
export interface LoopProgressCard {
    id: number;
    title: string;
    type: string;
    streak: LoopStreak;
    completion_rate: number | null; // 0–100, null when no decided logs
    totals: { completed: number; failed: number; skipped: number };
    recent: OutcomeMark[]; // oldest → newest, max 10
    last_logged_at: string | null;
    summary_excerpt: string | null;
}

/** The same metric block on the detail screen (no index-only excerpt). */
export type LoopProgressDetail = Omit<LoopProgressCard, 'summary_excerpt'>;
